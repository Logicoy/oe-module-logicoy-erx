<?php

/**
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Prescription\NotificationStoreInterface;

class InMemoryNotificationStore implements NotificationStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    /** @var array<string, int> erx patient uuid => pid */
    public array $patients = [];

    public function record(array $notification): array
    {
        $uuid = (string)$notification['uuid'];

        if (!isset($this->rows[$uuid])) {
            $this->rows[$uuid] = $notification + ['processed_at' => null, 'attempts' => 0, 'last_error' => null];
        } else {
            // Mirrors ON DUPLICATE KEY UPDATE: display fields only, never state.
            $this->rows[$uuid]['pid'] ??= $notification['pid'];
            $this->rows[$uuid]['message'] = $notification['message'];
        }

        return $this->rows[$uuid];
    }

    public function markProcessed(string $notificationUuid): void
    {
        $this->rows[$notificationUuid]['processed_at'] = '2026-01-01 00:00:00';
        $this->rows[$notificationUuid]['last_error'] = null;
    }

    public function markProcessingFailed(string $notificationUuid, string $error, int $maxAttempts): void
    {
        $row = &$this->rows[$notificationUuid];
        $row['attempts']++;
        $row['last_error'] = $error;
        $row['processed_at'] = $row['attempts'] >= $maxAttempts ? '2026-01-01 00:00:00' : null;
    }

    public function resolvePatientId(string $erxPatientUuid, int $facilityId): ?int
    {
        return $this->patients[$erxPatientUuid] ?? null;
    }

    public function saveRequestDetail(string $notificationUuid, array $detail): void
    {
        $this->rows[$notificationUuid]['request_detail'] = $detail;
    }

    public function attachPatient(
        string $notificationUuid,
        ?int $pid,
        string $erxPatientUuid,
        string $patientName
    ): void {
        $row = &$this->rows[$notificationUuid];
        $row['pid'] ??= $pid;
        $row['patient_uuid'] = ($row['patient_uuid'] ?? '') !== '' ? $row['patient_uuid'] : $erxPatientUuid;
        $row['patient_name'] = ($row['patient_name'] ?? '') !== '' ? $row['patient_name'] : $patientName;
    }
}
