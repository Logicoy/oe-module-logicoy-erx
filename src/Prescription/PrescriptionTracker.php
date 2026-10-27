<?php

/**
 * The record of prescriptions transmitted from this install.
 *
 * One row per LogiCoy medication uuid. It exists so a sent prescription can be
 * cancelled and its status checked from the prescribing screen, and so inbound
 * cancel and fill results have a row to land on. The backend remains the
 * source of truth; this is what the screen shows between lookups.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Prescription;

use OpenEMR\Common\Database\QueryUtils;

class PrescriptionTracker implements PrescriptionTrackerInterface
{
    public const TABLE = 'logicoy_erx_prescription';

    public const STATUS_SENT = 'sent';
    public const STATUS_CANCEL_QUEUED = 'cancel_queued';
    public const STATUS_CANCEL_REQUESTED = 'cancel_requested';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_CANCEL_DENIED = 'cancel_denied';
    public const STATUS_CANCEL_FAILED = 'cancel_failed';

    // How the fill indicator request made after the send went: waiting in
    // the queue, accepted by the backend, or refused / given up on.
    public const FILL_INDICATOR_QUEUED = 'queued';
    public const FILL_INDICATOR_SET = 'set';
    public const FILL_INDICATOR_FAILED = 'failed';

    /** Backend transmission states that mean the pharmacy never received it. */
    private const NOT_TRANSMITTED = ['FAILED', 'SYSTEM_ERROR', 'MAX_RETRY_REACHED'];

    /**
     * Record a transmitted RxNew. A repeat call for the same medication — a
     * double click, or a retry after a timeout — updates the one row.
     */
    public function recordSent(
        string $medicationUuid,
        int $pid,
        int $facilityId,
        ?int $providerUserId,
        string $drugName,
        string $transmissionStatus
    ): void {
        QueryUtils::sqlInsert(
            'INSERT INTO ' . self::TABLE . ' SET '
            . 'erx_medication_uuid = ?, pid = ?, facility_id = ?, provider_user_id = ?, drug_name = ?, '
            . 'status = ?, transmission_status = ?, sent_at = NOW(), created_at = NOW(), updated_at = NOW() '
            . 'ON DUPLICATE KEY UPDATE transmission_status = VALUES(transmission_status), updated_at = NOW()',
            [
                $medicationUuid,
                $pid,
                $facilityId,
                $providerUserId,
                mb_substr($drugName, 0, 255),
                self::STATUS_SENT,
                self::limit($transmissionStatus, 40),
            ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $medicationUuid): ?array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT * FROM ' . self::TABLE . ' WHERE erx_medication_uuid = ? LIMIT 1',
            [$medicationUuid]
        );

        return $rows[0] ?? null;
    }

    public function findPid(string $medicationUuid): ?int
    {
        if ($medicationUuid === '') {
            return null;
        }
        $row = $this->find($medicationUuid);

        return $row === null ? null : (int)$row['pid'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForPatient(int $pid, int $facilityId, int $limit = 500): array
    {
        return QueryUtils::fetchRecords(
            'SELECT * FROM ' . self::TABLE . ' WHERE pid = ? AND facility_id = ? '
            . 'ORDER BY sent_at DESC, id DESC LIMIT ' . (int)$limit,
            [$pid, $facilityId]
        );
    }

    /**
     * Every transmitted script this install recorded, oldest first, for the
     * chart backfill. The backfill decides what qualifies; this returns all
     * of them so the report can say why a row was left out.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAllSent(): array
    {
        return QueryUtils::fetchRecords('SELECT * FROM ' . self::TABLE . ' ORDER BY sent_at, id', []);
    }

    public function markCancelQueued(string $medicationUuid): void
    {
        $this->setStatus($medicationUuid, self::STATUS_CANCEL_QUEUED, '');
    }

    /**
     * The backend accepted the cancel and forwarded it; the pharmacy's answer
     * arrives later as a notification.
     */
    public function markCancelRequested(string $medicationUuid, string $cancelRequestUuid, string $detail): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . self::TABLE . ' SET status = ?, cancel_request_uuid = ?, status_detail = ?, '
            . 'updated_at = NOW() WHERE erx_medication_uuid = ?',
            [
                self::STATUS_CANCEL_REQUESTED,
                self::limit($cancelRequestUuid, 64) ?: null,
                self::limit($detail, 500) ?: null,
                $medicationUuid,
            ]
        );
    }

    public function markCancelFailed(string $medicationUuid, string $error): void
    {
        $this->setStatus($medicationUuid, self::STATUS_CANCEL_FAILED, $error);
    }

    public function applyCancelResponse(string $medicationUuid, string $outcome, string $detail): void
    {
        $this->setStatus($medicationUuid, $outcome, $detail);
    }

    public function applyFillStatus(string $medicationUuid, string $fillStatus, string $detail): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . self::TABLE . ' SET fill_status = ?, status_detail = ?, status_checked_at = NOW(), '
            . 'updated_at = NOW() WHERE erx_medication_uuid = ?',
            [self::limit($fillStatus, 40) ?: null, self::limit($detail, 500) ?: null, $medicationUuid]
        );
    }

    /**
     * The fill indicator request for this Rx is waiting in the outbound queue.
     * Replaces whatever an earlier attempt left, since the queue row has been
     * revived with it.
     */
    public function markFillIndicatorQueued(string $medicationUuid, string $indicator): void
    {
        $this->setFillIndicator($medicationUuid, $indicator, self::FILL_INDICATOR_QUEUED, '');
    }

    /**
     * The backend accepted the indicator; the pharmacy will report the
     * dispensing events it names.
     */
    public function markFillIndicatorSet(string $medicationUuid, string $indicator, string $detail): void
    {
        $this->setFillIndicator($medicationUuid, $indicator, self::FILL_INDICATOR_SET, $detail);
    }

    /**
     * The backend refused it, or the queue gave up. The Rx itself is
     * unaffected: it was sent before this was asked.
     */
    public function markFillIndicatorFailed(string $medicationUuid, string $indicator, string $error): void
    {
        $this->setFillIndicator($medicationUuid, $indicator, self::FILL_INDICATOR_FAILED, $error);
    }

    private function setFillIndicator(string $medicationUuid, string $indicator, string $status, string $detail): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . self::TABLE . ' SET fill_indicator = ?, fill_indicator_status = ?, '
            . 'fill_indicator_detail = ?, updated_at = NOW() WHERE erx_medication_uuid = ?',
            [self::limit($indicator, 2) ?: null, $status, self::limit($detail, 500) ?: null, $medicationUuid]
        );
    }

    public function applyTransmissionStatus(string $medicationUuid, string $status, string $detail): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . self::TABLE . ' SET transmission_status = ?, status_detail = ?, status_checked_at = NOW(), '
            . 'updated_at = NOW() WHERE erx_medication_uuid = ?',
            [self::limit($status, 40) ?: null, self::limit($detail, 500) ?: null, $medicationUuid]
        );
    }

    private function setStatus(string $medicationUuid, string $status, string $detail): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . self::TABLE . ' SET status = ?, status_detail = ?, updated_at = NOW() '
            . 'WHERE erx_medication_uuid = ?',
            [$status, self::limit($detail, 500) ?: null, $medicationUuid]
        );
    }

    /**
     * Whether the screen should offer a cancel for this row.
     *
     * A cancel that failed can be tried again; one already on its way or
     * answered cannot. A prescription the backend says never reached the
     * pharmacy has nothing to cancel. The backend makes the final call — it
     * refuses a cancel for anything not in a sent state.
     *
     * @param array<string, mixed> $row
     */
    public static function isCancellable(array $row): bool
    {
        $status = (string)($row['status'] ?? '');
        if (!in_array($status, [self::STATUS_SENT, self::STATUS_CANCEL_FAILED], true)) {
            return false;
        }

        return !in_array(strtoupper((string)($row['transmission_status'] ?? '')), self::NOT_TRANSMITTED, true);
    }

    /**
     * Whether the screen should offer to ask the pharmacy for fill reports.
     *
     * Only for a script the pharmacy received: the API sets the indicator on
     * a sent prescription and nothing else. Not while a request is already
     * queued, and not once one is set; a refused one, or a script sent before
     * the module asked, can be requested. The backend makes the final call.
     *
     * @param array<string, mixed> $row
     */
    public static function canRequestFillIndicator(array $row): bool
    {
        if ((string)($row['status'] ?? '') === self::STATUS_CANCELLED) {
            return false;
        }
        if (in_array(strtoupper((string)($row['transmission_status'] ?? '')), self::NOT_TRANSMITTED, true)) {
            return false;
        }

        return !in_array(
            (string)($row['fill_indicator_status'] ?? ''),
            [self::FILL_INDICATOR_QUEUED, self::FILL_INDICATOR_SET],
            true
        );
    }

    /**
     * How the inbox labels a cancel answer, from the status it left on the Rx.
     *
     * 'unknown' when this install has no row for the Rx (it was sent from
     * elsewhere); 'pending' when the answer arrived but its outcome was not
     * retrieved or not recognised.
     *
     * @return 'approved'|'denied'|'pending'|'unknown'
     */
    public static function cancelAnswer(?string $status): string
    {
        return match ($status) {
            null => 'unknown',
            self::STATUS_CANCELLED => 'approved',
            self::STATUS_CANCEL_DENIED => 'denied',
            default => 'pending',
        };
    }

    /**
     * Read the pharmacy's cancel answer into this module's lifecycle.
     *
     * Surescripts answers a CancelRx with Approved or Denied; the backend
     * reports that through rxCancelResponseStatus and rxCancelResponseCode
     * using words such as CANCELLED. Anything not recognisably one or the
     * other leaves the row waiting rather than guessing at an outcome.
     */
    public static function cancelOutcome(string $status, string $code): string
    {
        $words = strtoupper($status . ' ' . $code);

        if (preg_match('/DENIED|REJECT|NOT_CANCEL|DECLIN/', $words)) {
            return self::STATUS_CANCEL_DENIED;
        }
        if (preg_match('/CANCELLED|CANCELED|APPROVED|ACCEPTED|SUCCESS/', $words)) {
            return self::STATUS_CANCELLED;
        }

        return self::STATUS_CANCEL_REQUESTED;
    }

    private static function limit(string $value, int $length): string
    {
        return mb_substr(trim($value), 0, $length);
    }
}
