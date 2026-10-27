<?php

/**
 * The queue worker as an OpenEMR background service, so a practice does not
 * have to schedule anything.
 *
 * OpenEMR runs every due row of its background_services table: on 8.x the
 * main screen asks for that every minute while anyone is signed in, and
 * `bin/console background:services run` does it from cron. 7.0 runs them the
 * same way through library/ajax/execute_background_services.php. Each run
 * here sends what prescribers queued and polls every facility that has an
 * active practice credential. scripts/queue_worker.php remains for practices
 * that want a schedule of their own; both are safe to overlap, because rows
 * are claimed atomically and every message is keyed on its LogiCoy id.
 *
 * The row follows the module: added on install and upgrade, switched on and
 * off with Enable and Disable in Manage Modules, removed on unregister.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Queue;

use LogiCoy\ERx\Client\BackendClient;
use LogiCoy\ERx\Config\CredentialStore;
use LogiCoy\ERx\Config\GlobalConfig;
use LogiCoy\ERx\Config\SessionContext;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use Throwable;

class BackgroundSync
{
    /** background_services.name is VARCHAR(31). */
    public const SERVICE_NAME = 'LogiCoy_ERx_Sync';
    public const FUNCTION_NAME = 'logicoyErxBackgroundSync';
    public const MODULE_DIRECTORY = 'oe-module-logicoy-erx';
    /** Relative to the OpenEMR root, as background_services.require_once expects. */
    public const INCLUDE_PATH = '/interface/modules/custom_modules/' . self::MODULE_DIRECTORY
        . '/scripts/background_service.php';
    public const INTERVAL_MINUTES = 5;

    private const TABLE = 'background_services';

    /**
     * Add the row, or bring an existing one up to date. Never touches
     * next_run or the lock columns, so a run in progress is left alone.
     *
     * @param bool|null $active null follows whether Manage Modules has the
     *                          module enabled
     */
    public static function register(?bool $active = null): void
    {
        $active ??= self::isModuleActive();

        QueryUtils::sqlStatementThrowException(
            'INSERT INTO ' . self::TABLE . ' (`name`, `title`, `active`, `running`, `next_run`, '
            . '`execute_interval`, `function`, `require_once`, `sort_order`) '
            . 'VALUES (?, ?, ?, 0, NOW(), ?, ?, ?, 100) '
            . 'ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `active` = VALUES(`active`), '
            . '`execute_interval` = VALUES(`execute_interval`), `function` = VALUES(`function`), '
            . '`require_once` = VALUES(`require_once`)',
            [
                self::SERVICE_NAME,
                'LogiCoy eRx sync',
                $active ? 1 : 0,
                self::INTERVAL_MINUTES,
                self::FUNCTION_NAME,
                self::INCLUDE_PATH,
            ]
        );
    }

    /**
     * Put the row back if it is missing or off while the module is enabled.
     *
     * The row is normally added by Manage Modules, but an install that only
     * replaced the files never ran that step, and without the row nothing
     * sends the queue. Called from screens the module already serves, so a
     * practice never has to schedule anything.
     *
     * @return array{active: bool, next_run: string, execute_interval: int}|null
     */
    public static function ensureRegistered(): ?array
    {
        $status = self::status();
        if (($status === null || !$status['active']) && self::isModuleActive()) {
            self::register(true);
            $status = self::status();
        }

        return $status;
    }

    /**
     * Bring the next run forward to now, so something just queued goes out
     * on OpenEMR's next background tick (about a minute while anyone is
     * signed in) rather than after the full interval. The lock columns are
     * left alone, so a run in progress is not disturbed.
     */
    public static function runSoon(): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . self::TABLE . ' SET `next_run` = NOW() '
            . 'WHERE `name` = ? AND `active` = 1 AND `next_run` > NOW()',
            [self::SERVICE_NAME]
        );
    }

    public static function remove(): void
    {
        QueryUtils::sqlStatementThrowException(
            'DELETE FROM ' . self::TABLE . ' WHERE `name` = ?',
            [self::SERVICE_NAME]
        );
    }

    /**
     * The registered row, for the setup screen.
     *
     * @return array{active: bool, next_run: string, execute_interval: int}|null
     */
    public static function status(): ?array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT `active`, `next_run`, `execute_interval` FROM ' . self::TABLE . ' WHERE `name` = ?',
            [self::SERVICE_NAME]
        );
        if (!isset($rows[0])) {
            return null;
        }

        return [
            'active' => (int)$rows[0]['active'] === 1,
            'next_run' => (string)$rows[0]['next_run'],
            'execute_interval' => (int)$rows[0]['execute_interval'],
        ];
    }

    public static function isModuleActive(): bool
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT `mod_active` FROM `modules` WHERE `mod_directory` = ?',
            [self::MODULE_DIRECTORY]
        );

        return !empty($rows[0]['mod_active']);
    }

    /**
     * One background run. Does nothing while the module is disabled in Manage
     * Modules or switched off in its settings.
     *
     * @return array<string, mixed> counts, for the system log
     */
    public static function run(): array
    {
        $logger = new SystemLogger();
        $config = new GlobalConfig($GLOBALS);
        if (!self::isModuleActive() || !$config->isModuleEnabled()) {
            $logger->debug('LogiCoy eRx background sync skipped: module disabled');
            return ['skipped' => true];
        }

        $credentials = new CredentialStore();
        $worker = new QueueWorker(
            $config,
            new QueueRepository(),
            new BackendClient($config, $credentials),
            $credentials
        );

        return SessionContext::runAsWorker(
            static fn(): array => self::sync($worker, $credentials->listPollingFacilityIds(), $logger)
        );
    }

    /**
     * Send the queue, then poll each facility. One facility that fails to poll
     * (a revoked credential, say) is logged and does not hold up the others.
     *
     * @param int[] $facilityIds
     * @return array{outbound: array<string, int>, inbound: array<int, array<string, int>|string>}
     */
    public static function sync(QueueWorker $worker, array $facilityIds, SystemLogger $logger): array
    {
        $outbound = $worker->drainOutbound();
        $logger->info('LogiCoy eRx outbound drained', $outbound);

        $inbound = [];
        foreach ($facilityIds as $facilityId) {
            try {
                $inbound[$facilityId] = $worker->pollInbound($facilityId);
                $logger->info('LogiCoy eRx inbound polled', ['facility' => $facilityId] + $inbound[$facilityId]);
            } catch (Throwable $e) {
                $inbound[$facilityId] = 'failed';
                $logger->error('LogiCoy eRx inbound poll failed', [
                    'facility' => $facilityId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['outbound' => $outbound, 'inbound' => $inbound];
    }
}
