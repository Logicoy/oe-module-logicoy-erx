<?php

/**
 * Called by the Laminas Module Manager on install / enable / disable /
 * unregister. Whatever action lands here has already happened in the manager,
 * so exceptions are caught and reported rather than thrown.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use OpenEMR\Core\AbstractModuleActionListener;

class ModuleManagerListener extends AbstractModuleActionListener
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @return string the current action status, or a description of the error
     */
    public function moduleManagerAction($methodName, $modId, string $currentActionStatus = 'Success'): string
    {
        if (method_exists(self::class, $methodName)) {
            return self::$methodName($modId, $currentActionStatus);
        }

        return $currentActionStatus;
    }

    public static function getModuleNamespace(): string
    {
        return 'LogiCoy\\ERx\\';
    }

    public static function initListenerSelf(): ModuleManagerListener
    {
        return new self();
    }

    /**
     * @return mixed
     */
    private function install($modId, $currentActionStatus): mixed
    {
        try {
            $this->installTables();
        } catch (Throwable $e) {
            return 'LogiCoy eRx install failed: ' . $e->getMessage();
        }
        // Follows Manage Modules: off until Enable, unless this is the
        // installer's schema step on a module that is already enabled.
        $this->scheduleSync(null);

        return $currentActionStatus;
    }

    /**
     * @return mixed
     */
    private function enable($modId, $currentActionStatus): mixed
    {
        try {
            // Installing is idempotent (#IfNotTable), so enabling after a
            // partial install still lands on a complete schema.
            $this->installTables();
        } catch (Throwable $e) {
            return 'LogiCoy eRx enable failed: ' . $e->getMessage();
        }
        $this->scheduleSync(true);
        $this->backfillChart();

        return $currentActionStatus;
    }

    /**
     * Disabling leaves the schema in place — parking prescription history on a
     * toggle would lose the support trail.
     *
     * @return mixed
     */
    private function disable($modId, $currentActionStatus): mixed
    {
        $this->scheduleSync(false);

        return $currentActionStatus;
    }

    /**
     * @return mixed
     */
    private function unregister($modId, $currentActionStatus): mixed
    {
        // Deliberately does not drop tables. Prescription records are audited
        // clinical data; removal is a manual, deliberate act by an admin. The
        // background service goes, though: its file goes with the module.
        try {
            $this->registerModuleAutoloader();
            \LogiCoy\ERx\Queue\BackgroundSync::remove();
        } catch (Throwable $e) {
            (new \OpenEMR\Common\Logging\SystemLogger())->error('LogiCoy eRx background sync not removed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $currentActionStatus;
    }

    /**
     * @return mixed
     */
    private function install_sql($modId, $currentActionStatus): mixed
    {
        return $this->install($modId, $currentActionStatus);
    }

    /**
     * @return mixed
     */
    private function upgrade_sql($modId, $currentActionStatus): mixed
    {
        try {
            $upgradeDir = __DIR__ . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'upgrade';
            if (is_dir($upgradeDir)) {
                $files = glob($upgradeDir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
                sort($files);
                foreach ($files as $file) {
                    $this->runSqlFile($file);
                }
            }
        } catch (Throwable $e) {
            return 'LogiCoy eRx upgrade failed: ' . $e->getMessage();
        }
        $this->scheduleSync(null);
        $this->backfillChart();

        return $currentActionStatus;
    }

    /**
     * Add or update the background service that sends the queue and polls
     * for pharmacy notifications, so no cron entry is needed. Logged and
     * never fails the action: the setup screen shows when it is missing and
     * puts it back.
     *
     * @param bool|null $active null follows the module's state in Manage Modules
     */
    private function scheduleSync(?bool $active): void
    {
        try {
            $this->registerModuleAutoloader();
            \LogiCoy\ERx\Queue\BackgroundSync::register($active);
        } catch (Throwable $e) {
            (new \OpenEMR\Common\Logging\SystemLogger())->error('LogiCoy eRx background sync not registered', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Put scripts sent before the chart write-back onto the chart.
     *
     * Idempotent (keyed on the medication uuid through
     * logicoy_erx_message_map), so running it on every enable and upgrade is
     * harmless. A failure here is logged and never fails the action: the
     * schema step has already succeeded, and scripts/backfill_prescriptions.php
     * can be run by hand.
     */
    private function backfillChart(): void
    {
        try {
            $this->registerModuleAutoloader();
            $config = new \LogiCoy\ERx\Config\GlobalConfig($GLOBALS);
            $sentTable = \LogiCoy\ERx\Prescription\PrescriptionTracker::TABLE;
            if (
                !$config->shouldWriteToPrescriptions()
                || !\OpenEMR\Common\Database\QueryUtils::existsTable($sentTable)
            ) {
                return;
            }
            $counts = (new \LogiCoy\ERx\Prescription\PrescriptionBackfill())->run(
                (new \LogiCoy\ERx\Prescription\PrescriptionTracker())->listAllSent()
            );
            (new \OpenEMR\Common\Logging\SystemLogger())->info('LogiCoy eRx chart backfill', $counts);
        } catch (Throwable $e) {
            (new \OpenEMR\Common\Logging\SystemLogger())->error('LogiCoy eRx chart backfill failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The module's classes are autoloaded once the module system has picked
     * it up; this action can run before that (a first enable, or the dev
     * helper), so register PSR-4 for the namespace directly, as the CLI
     * bootstrap does.
     */
    private function registerModuleAutoloader(): void
    {
        if (class_exists(\LogiCoy\ERx\Prescription\PrescriptionBackfill::class)) {
            return;
        }
        spl_autoload_register(static function (string $class): void {
            $prefix = self::getModuleNamespace();
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $file = __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR
                . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    private function installTables(): void
    {
        $this->runSqlFile(__DIR__ . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'install.sql');
    }

    /**
     * Runs a file through OpenEMR's SQL upgrade service so the #IfNotTable /
     * #EndIf directives are honoured.
     */
    private function runSqlFile(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $service = new \OpenEMR\Services\Utils\SQLUpgradeService();
        $service->setThrowExceptionOnError(true);
        $service->setRenderOutputToScreen(false);
        $service->upgradeFromSqlFile(basename($path), dirname($path) . DIRECTORY_SEPARATOR);
    }
}
