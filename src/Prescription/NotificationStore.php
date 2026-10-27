<?php

/**
 * Pharmacy notifications polled from the LogiCoy backend.
 *
 * Renewal and change requests wait here for the prescriber; cancel, fill and
 * verification results are recorded here once their follow-up lookup has run.
 * Keyed on the LogiCoy notification uuid, because /v1/notifications/get
 * returns every notification on every call, not only new ones.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Prescription;

use OpenEMR\Common\Database\QueryUtils;

class NotificationStore implements NotificationStoreInterface
{
    public const TABLE = 'logicoy_erx_notification';

    public const STATE_OPEN = 'open';
    public const STATE_RESPONSE_QUEUED = 'response_queued';
    public const STATE_RESPONDED = 'responded';
    public const STATE_RESPONSE_FAILED = 'response_failed';
    public const STATE_INFO = 'info';

    /** Same value as NotificationPoller::TYPE_CANCEL_RESPONSE. */
    public const TYPE_CANCEL_RESPONSE = 'RX_CANCEL_RESPONSE';

    /** States in which the prescriber still owes the pharmacy an answer. */
    public const AWAITING_RESPONSE = [self::STATE_OPEN, self::STATE_RESPONSE_FAILED];

    public function record(array $notification): array
    {
        QueryUtils::sqlInsert(
            'INSERT INTO ' . self::TABLE . ' SET '
            . 'erx_notification_uuid = ?, facility_id = ?, pid = ?, rx_type = ?, erx_medication_uuid = ?, '
            . 'erx_patient_uuid = ?, erx_clinician_uuid = ?, patient_name = ?, clinician_name = ?, '
            . 'message = ?, priority = ?, reply_required = ?, requested_at = ?, due_at = ?, state = ?, '
            . 'attempts = 0, created_at = NOW(), updated_at = NOW() '
            // Display fields only. State, responses and processing are this
            // module's own and a replayed poll must not touch them.
            . 'ON DUPLICATE KEY UPDATE pid = COALESCE(pid, VALUES(pid)), message = VALUES(message), '
            . 'priority = VALUES(priority), due_at = VALUES(due_at), updated_at = NOW()',
            [
                (string)$notification['uuid'],
                (int)$notification['facility_id'],
                $notification['pid'],
                self::limit($notification['rx_type'] ?? '', 48),
                self::nullable($notification['medication_uuid'] ?? '', 64),
                self::nullable($notification['patient_uuid'] ?? '', 64),
                self::nullable($notification['clinician_uuid'] ?? '', 64),
                self::nullable($notification['patient_name'] ?? '', 255),
                self::nullable($notification['clinician_name'] ?? '', 255),
                self::nullable($notification['message'] ?? '', 1000),
                self::nullable($notification['priority'] ?? '', 16),
                !empty($notification['reply_required']) ? 1 : 0,
                $notification['requested_at'] ?? null,
                $notification['due_at'] ?? null,
                (string)$notification['state'],
            ]
        );

        return $this->find((string)$notification['uuid']) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $notificationUuid): ?array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT * FROM ' . self::TABLE . ' WHERE erx_notification_uuid = ? LIMIT 1',
            [$notificationUuid]
        );

        return $rows[0] ?? null;
    }

    public function markProcessed(string $notificationUuid): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . self::TABLE . ' SET processed_at = NOW(), last_error = NULL, updated_at = NOW() '
            . 'WHERE erx_notification_uuid = ?',
            [$notificationUuid]
        );
    }

    public function markProcessingFailed(string $notificationUuid, string $error, int $maxAttempts): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . self::TABLE . ' SET attempts = attempts + 1, last_error = ?, '
            . 'processed_at = IF(attempts >= ?, NOW(), NULL), updated_at = NOW() '
            . 'WHERE erx_notification_uuid = ?',
            // MySQL applies SET left to right, so attempts is already the new
            // count when processed_at is decided.
            [self::nullable($error, 500), max(1, $maxAttempts), $notificationUuid]
        );
    }

    public function resolvePatientId(string $erxPatientUuid, int $facilityId): ?int
    {
        if ($erxPatientUuid === '') {
            return null;
        }

        $pid = QueryUtils::fetchSingleValue(
            'SELECT pid FROM ' . PatientSync::TABLE . ' WHERE erx_patient_pk_uuid = ? AND facility_id = ? LIMIT 1',
            'pid',
            [$erxPatientUuid, $facilityId]
        );

        return empty($pid) ? null : (int)$pid;
    }

    /**
     * Requests for the inbox or a patient's screen: anything still owed an
     * answer or on its way, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listActionable(int $facilityId, ?int $pid = null, int $limit = 100): array
    {
        $sql = 'SELECT id, erx_notification_uuid, facility_id, pid, rx_type, erx_medication_uuid, '
            . 'erx_clinician_uuid, patient_name, clinician_name, message, priority, reply_required, '
            . 'requested_at, due_at, state, response_code, responded_at, last_error '
            . 'FROM ' . self::TABLE . ' WHERE facility_id = ? AND state IN (?, ?, ?)';
        $binds = [$facilityId, self::STATE_OPEN, self::STATE_RESPONSE_QUEUED, self::STATE_RESPONSE_FAILED];

        if ($pid !== null) {
            $sql .= ' AND pid = ?';
            $binds[] = $pid;
        }

        $sql .= ' ORDER BY COALESCE(due_at, requested_at, created_at) ASC LIMIT ' . (int)$limit;

        return QueryUtils::fetchRecords($sql, $binds);
    }

    /**
     * The inbox's read-only record, newest first: the pharmacy's answers to
     * cancel requests, and renewal and change requests already answered here.
     * Joined to the prescription each concerns; rx_status is NULL for an Rx
     * this install did not send.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listRecentUpdates(int $facilityId, int $days = 30, int $limit = 200): array
    {
        return QueryUtils::fetchRecords(
            'SELECT n.erx_notification_uuid, n.pid, n.rx_type, n.patient_name, n.clinician_name, n.message, '
            . 'n.state, n.response_code, n.responded_at, n.created_at, n.processed_at, n.last_error, '
            . 'COALESCE(n.responded_at, n.created_at) AS happened_at, '
            . 'u.fname AS responder_fname, u.lname AS responder_lname, '
            . 'p.drug_name, p.status AS rx_status, p.status_detail AS rx_status_detail '
            . 'FROM ' . self::TABLE . ' n '
            . 'LEFT JOIN ' . PrescriptionTracker::TABLE . ' p ON p.erx_medication_uuid = n.erx_medication_uuid '
            . 'LEFT JOIN users u ON u.id = n.responded_by '
            . 'WHERE n.facility_id = ? AND (n.rx_type = ? OR n.state = ?) '
            . 'AND COALESCE(n.responded_at, n.created_at) >= NOW() - INTERVAL ? DAY '
            . 'ORDER BY happened_at DESC, n.id DESC LIMIT ' . (int)$limit,
            [$facilityId, self::TYPE_CANCEL_RESPONSE, self::STATE_RESPONDED, max(1, $days)]
        );
    }

    public function attachPatient(
        string $notificationUuid,
        ?int $pid,
        string $erxPatientUuid,
        string $patientName
    ): void {
        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . self::TABLE . ' SET pid = COALESCE(pid, ?), '
            . 'erx_patient_uuid = COALESCE(erx_patient_uuid, ?), patient_name = COALESCE(patient_name, ?), '
            . 'updated_at = NOW() WHERE erx_notification_uuid = ?',
            [$pid, self::nullable($erxPatientUuid, 64), self::nullable($patientName, 255), $notificationUuid]
        );
    }

    /**
     * Link a request to an OpenEMR patient by hand. Guarded on the request
     * still being unlinked, so two users linking at once cannot point it at
     * two charts; returns false when it was already linked.
     */
    public function linkPatient(string $notificationUuid, int $pid): bool
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . self::TABLE . ' SET pid = ?, updated_at = NOW() '
            . 'WHERE erx_notification_uuid = ? AND pid IS NULL',
            [$pid, $notificationUuid]
        );

        return (int)QueryUtils::fetchSingleValue('SELECT ROW_COUNT() AS c', 'c', []) > 0;
    }

    public function countAwaitingForPatient(int $pid): int
    {
        return (int)QueryUtils::fetchSingleValue(
            'SELECT COUNT(*) AS c FROM ' . self::TABLE . ' WHERE pid = ? AND state IN (?, ?)',
            'c',
            [$pid, self::STATE_OPEN, self::STATE_RESPONSE_FAILED]
        );
    }

    /**
     * Cache what get-details returned that a response has to echo back.
     *
     * @param array<string, mixed> $detail
     */
    public function saveRequestDetail(string $notificationUuid, array $detail): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . self::TABLE . ' SET request_detail = ?, updated_at = NOW() WHERE erx_notification_uuid = ?',
            [json_encode($detail, JSON_THROW_ON_ERROR), $notificationUuid]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getRequestDetail(string $notificationUuid): array
    {
        $raw = QueryUtils::fetchSingleValue(
            'SELECT request_detail FROM ' . self::TABLE . ' WHERE erx_notification_uuid = ?',
            'request_detail',
            [$notificationUuid]
        );
        $decoded = json_decode((string)$raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Claim the request for a response. Only one response may be in flight or
     * sent per request; returns false when another one got there first.
     */
    public function markResponseQueued(string $notificationUuid, string $responseCode, int $userId): bool
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . self::TABLE . ' SET state = ?, response_code = ?, responded_by = ?, last_error = NULL, '
            . 'updated_at = NOW() WHERE erx_notification_uuid = ? AND state IN (?, ?)',
            [
                self::STATE_RESPONSE_QUEUED,
                self::limit($responseCode, 32),
                $userId,
                $notificationUuid,
                self::STATE_OPEN,
                self::STATE_RESPONSE_FAILED,
            ]
        );

        return (int)QueryUtils::fetchSingleValue('SELECT ROW_COUNT() AS c', 'c', []) > 0;
    }

    public function markResponded(string $notificationUuid): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . self::TABLE . ' SET state = ?, responded_at = NOW(), last_error = NULL, updated_at = NOW() '
            . 'WHERE erx_notification_uuid = ?',
            [self::STATE_RESPONDED, $notificationUuid]
        );
    }

    /**
     * The response could not be delivered. The request goes back to the
     * prescriber with the reason, rather than looking answered when it is not.
     */
    public function markResponseFailed(string $notificationUuid, string $error): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . self::TABLE . ' SET state = ?, last_error = ?, updated_at = NOW() '
            . 'WHERE erx_notification_uuid = ?',
            [self::STATE_RESPONSE_FAILED, self::nullable($error, 500), $notificationUuid]
        );
    }

    private static function limit(mixed $value, int $length): string
    {
        return mb_substr(trim((string)$value), 0, $length);
    }

    private static function nullable(mixed $value, int $length): ?string
    {
        $value = self::limit($value, $length);

        return $value === '' ? null : $value;
    }
}
