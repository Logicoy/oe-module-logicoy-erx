<?php

/**
 * Puts prescriptions sent before the chart write-back existed onto the chart.
 *
 * Until 2026-10-19 a script sent through the LogiCoy screen was recorded in
 * logicoy_erx_prescription only, so the patient's Prescriptions card never
 * showed it. This walks those rows and reflects each one through
 * PrescriptionWriter under the same key the send path now uses (the
 * medication uuid), so a row the send path already wrote, and a repeated run,
 * add nothing. Only what the module recorded is available: the drug name,
 * patient, prescriber and date. The sig and quantity live on the backend.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Prescription;

use Throwable;

class PrescriptionBackfill
{
    public const RESULT_WRITTEN = 'written';
    public const RESULT_EXISTS = 'exists';
    public const RESULT_SKIPPED = 'skipped';
    public const RESULT_FAILED = 'failed';

    /** Transmission states that mean the pharmacy never received the script. */
    private const NOT_TRANSMITTED = ['FAILED', 'SYSTEM_ERROR', 'MAX_RETRY_REACHED'];

    private PrescriptionWriter $writer;

    public function __construct(?PrescriptionWriter $writer = null)
    {
        // Resolved lazily by the writer itself; nothing touches OpenEMR until run().
        $this->writer = $writer ?? new PrescriptionWriter();
    }

    /**
     * Whether a sent-prescription row describes a script the chart should show.
     *
     * @param array<string, mixed> $row a logicoy_erx_prescription row
     * @return string|null why it is left out, or null when it qualifies
     */
    public static function skipReason(array $row): ?string
    {
        if ((string)($row['erx_medication_uuid'] ?? '') === '') {
            return 'no medication uuid';
        }
        if ((int)($row['pid'] ?? 0) <= 0) {
            return 'no patient';
        }
        if ((string)($row['status'] ?? '') !== PrescriptionTracker::STATUS_SENT) {
            return 'status ' . (string)$row['status'];
        }
        $transmission = strtoupper(trim((string)($row['transmission_status'] ?? '')));
        if (in_array($transmission, self::NOT_TRANSMITTED, true)) {
            return 'never transmitted (' . $transmission . ')';
        }

        return null;
    }

    /**
     * Reflect every qualifying row. Safe to repeat.
     *
     * @param iterable<array<string, mixed>> $rows logicoy_erx_prescription rows
     * @param callable|null $report called per row with (medication uuid, pid, result, detail);
     *     identifiers only, never the drug
     * @return array{considered: int, written: int, exists: int, skipped: int, failed: int}
     */
    public function run(iterable $rows, bool $dryRun = false, ?callable $report = null): array
    {
        $counts = ['considered' => 0, 'written' => 0, 'exists' => 0, 'skipped' => 0, 'failed' => 0];
        $report ??= static function (): void {
        };

        foreach ($rows as $row) {
            $counts['considered']++;
            $uuid = (string)($row['erx_medication_uuid'] ?? '');
            $pid = (int)($row['pid'] ?? 0);

            $reason = self::skipReason($row);
            if ($reason !== null) {
                $counts['skipped']++;
                $report($uuid, $pid, self::RESULT_SKIPPED, $reason);
                continue;
            }

            if ($dryRun) {
                $existing = $this->writer->existingPrescriptionId($uuid);
                $result = $existing === null ? self::RESULT_WRITTEN : self::RESULT_EXISTS;
                $counts[$result]++;
                $report($uuid, $pid, $result, $existing === null ? 'would write' : 'prescription ' . $existing);
                continue;
            }

            try {
                $outcome = $this->writer->reflect($uuid, $pid, self::medication($row));
            } catch (Throwable $e) {
                $counts['failed']++;
                $report($uuid, $pid, self::RESULT_FAILED, $e->getMessage());
                continue;
            }
            $result = $outcome['created'] ? self::RESULT_WRITTEN : self::RESULT_EXISTS;
            $counts[$result]++;
            $report($uuid, $pid, $result, 'prescription ' . $outcome['prescription_id']);
        }

        return $counts;
    }

    /**
     * What the module recorded, in the writer's shape.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function medication(array $row): array
    {
        $sentAt = trim((string)($row['sent_at'] ?? ''));
        $medication = [
            'drugName' => trim((string)($row['drug_name'] ?? '')) ?: xl('Prescription sent through LogiCoy eRx'),
            'effectiveDate' => $sentAt,
            'dateAdded' => $sentAt,
        ];
        if (!empty($row['provider_user_id'])) {
            $medication['providerUserId'] = (int)$row['provider_user_id'];
        }

        return $medication;
    }
}
