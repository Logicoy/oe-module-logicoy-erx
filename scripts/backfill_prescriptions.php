<?php

/**
 * Put prescriptions sent before the chart write-back onto the patient chart.
 *
 * Scripts sent from the LogiCoy screen before module build 2026-10-19 were
 * recorded in logicoy_erx_prescription only. This writes each one into
 * OpenEMR's prescriptions table through the same path the send now uses,
 * keyed on the medication uuid, so a repeat run adds nothing. It also runs
 * once by itself when the module is enabled or upgraded.
 *
 *   php scripts/backfill_prescriptions.php [--dry-run] [--site=default]
 *
 * The report names medication uuids and patient ids only, never the drug.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/cli_bootstrap.php';

use LogiCoy\ERx\Config\GlobalConfig;
use LogiCoy\ERx\Prescription\PrescriptionBackfill;
use LogiCoy\ERx\Prescription\PrescriptionTracker;
use OpenEMR\Common\Database\QueryUtils;

$options = getopt('', ['dry-run', 'site::']);
$dryRun = isset($options['dry-run']);

$config = new GlobalConfig($GLOBALS);
if (!$config->shouldWriteToPrescriptions()) {
    fwrite(STDERR, "Write to the OpenEMR prescriptions table is switched off; nothing to do.\n");
    exit(0);
}
if (!QueryUtils::existsTable(PrescriptionTracker::TABLE)) {
    fwrite(STDERR, "No sent-prescription table; nothing to backfill.\n");
    exit(0);
}

echo $dryRun ? "Dry run: nothing will be written.\n" : "Backfilling sent prescriptions into the chart.\n";

$counts = (new PrescriptionBackfill())->run(
    (new PrescriptionTracker())->listAllSent(),
    $dryRun,
    static function (string $uuid, int $pid, string $result, string $detail): void {
        printf("  %-8s medication %s patient %d: %s\n", $result, $uuid, $pid, $detail);
    }
);

printf(
    "Considered %d: %d written, %d already on the chart, %d skipped, %d failed.\n",
    $counts['considered'],
    $counts['written'],
    $counts['exists'],
    $counts['skipped'],
    $counts['failed']
);

exit($counts['failed'] > 0 ? 1 : 0);
