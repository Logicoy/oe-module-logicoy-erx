<?php

/**
 * Cron entry point: drains the outbound queue and polls for inbound traffic.
 *
 * Safe to run concurrently and safe to replay — rows are claimed atomically and
 * every message is keyed on its LogiCoy message id.
 *
 *   php scripts/queue_worker.php [--facility=1] [--batch=25]
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/cli_bootstrap.php';

use LogiCoy\ERx\Client\BackendClient;
use LogiCoy\ERx\Config\CredentialStore;
use LogiCoy\ERx\Config\GlobalConfig;
use LogiCoy\ERx\Queue\QueueRepository;
use LogiCoy\ERx\Queue\QueueWorker;
use OpenEMR\Common\Logging\SystemLogger;

$options = getopt('', ['facility::', 'batch::']);
$facilityId = isset($options['facility']) ? (int)$options['facility'] : 0;
$batchSize = isset($options['batch']) ? (int)$options['batch'] : 25;

$logger = new SystemLogger();
$config = new GlobalConfig($GLOBALS);

if (!$config->isModuleEnabled()) {
    $logger->debug('LogiCoy eRx worker skipped: module disabled');
    exit(0);
}

$credentials = new CredentialStore();
$queue = new QueueRepository();
$backend = new BackendClient($config, $credentials);
$worker = new QueueWorker($config, $queue, $backend, $credentials);

try {
    $outbound = $worker->drainOutbound($batchSize);
    $logger->info('LogiCoy eRx outbound drained', $outbound);

    if ($facilityId > 0) {
        $inbound = $worker->pollInbound($facilityId);
        $logger->info('LogiCoy eRx inbound polled', $inbound);
    }
} catch (Throwable $e) {
    $logger->error('LogiCoy eRx worker failed', ['error' => $e->getMessage()]);
    exit(1);
}

exit(0);
