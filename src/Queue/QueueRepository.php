<?php

/**
 * The outbound/inbound queue.
 *
 * OpenEMR's cron is unreliable on shared hosting and will replay, so every
 * operation here is keyed on the LogiCoy message id and is safe to run twice.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Queue;

use OpenEMR\Common\Database\QueryUtils;

class QueueRepository
{
    public const TABLE = 'logicoy_erx_queue';

    public const DIRECTION_OUTBOUND = 'outbound';
    public const DIRECTION_INBOUND = 'inbound';

    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_FLIGHT = 'in_flight';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PARKED = 'parked';

    /**
     * Enqueue a message, or return the existing row if this message id is
     * already queued. This is the idempotency guarantee the sync design
     * depends on: a replayed cron or a double-clicked button must never
     * produce a second script.
     *
     * @param array<string, mixed> $payload
     */
    public function enqueue(
        string $direction,
        string $messageType,
        string $logicoyMessageId,
        array $payload,
        ?int $patientId = null,
        ?int $facilityId = null,
        ?int $providerUserId = null
    ): int {
        $existing = $this->findByMessageId($logicoyMessageId);
        if ($existing !== null) {
            return (int)$existing['id'];
        }

        $sql = 'INSERT INTO ' . self::TABLE . ' SET '
            . 'direction = ?, message_type = ?, logicoy_message_id = ?, payload = ?, '
            . 'patient_id = ?, facility_id = ?, provider_user_id = ?, '
            . 'status = ?, attempts = 0, created_at = NOW(), updated_at = NOW() '
            // A concurrent insert loses the race on the unique key; keep the winner.
            . 'ON DUPLICATE KEY UPDATE updated_at = NOW()';

        QueryUtils::sqlInsert($sql, [
            $direction,
            $messageType,
            $logicoyMessageId,
            json_encode($payload, JSON_THROW_ON_ERROR),
            $patientId,
            $facilityId,
            $providerUserId,
            self::STATUS_PENDING,
        ]);

        $row = $this->findByMessageId($logicoyMessageId);

        return $row === null ? 0 : (int)$row['id'];
    }

    /**
     * Enqueue a prescriber-initiated transmission, keyed on what it acts on.
     *
     * Same guarantee as enqueue(): a double click or a resubmitted form finds
     * the row already pending, in flight or sent, and queues nothing. The one
     * difference is a row that was parked — the backend refused it, and the
     * prescriber has been shown why. Trying again must be possible, so that
     * row goes back to pending with the new payload instead of the retry being
     * swallowed as a duplicate.
     *
     * @param array<string, mixed> $payload
     * @return array{id: int, queued: bool}
     */
    public function enqueueOrRevive(
        string $messageType,
        string $logicoyMessageId,
        array $payload,
        ?int $patientId,
        ?int $facilityId,
        ?int $providerUserId
    ): array {
        $existing = $this->findByMessageId($logicoyMessageId);

        if ($existing === null) {
            $id = $this->enqueue(
                self::DIRECTION_OUTBOUND,
                $messageType,
                $logicoyMessageId,
                $payload,
                $patientId,
                $facilityId,
                $providerUserId
            );
            return ['id' => $id, 'queued' => $id > 0];
        }

        if (!in_array($existing['status'], [self::STATUS_PARKED, self::STATUS_FAILED], true)) {
            return ['id' => (int)$existing['id'], 'queued' => false];
        }

        // Guarded on status so two retries racing each other revive it once.
        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . self::TABLE . ' SET status = ?, payload = ?, response = NULL, attempts = 0, '
            . 'last_error = NULL, claim_token = NULL, provider_user_id = ?, completed_at = NULL, updated_at = NOW() '
            . 'WHERE id = ? AND status IN (?, ?)',
            [
                self::STATUS_PENDING,
                json_encode($payload, JSON_THROW_ON_ERROR),
                $providerUserId,
                (int)$existing['id'],
                self::STATUS_PARKED,
                self::STATUS_FAILED,
            ]
        );
        $revived = (int)QueryUtils::fetchSingleValue('SELECT ROW_COUNT() AS c', 'c', []) > 0;

        return ['id' => (int)$existing['id'], 'queued' => $revived];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByMessageId(string $logicoyMessageId): ?array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT * FROM ' . self::TABLE . ' WHERE logicoy_message_id = ? LIMIT 1',
            [$logicoyMessageId]
        );

        return $rows[0] ?? null;
    }

    /**
     * Claim a batch of pending rows.
     *
     * The UPDATE runs before the SELECT so two overlapping workers cannot pick
     * up the same row — whichever claims it first owns it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function claimBatch(string $direction, int $limit, int $maxAttempts): array
    {
        $claimToken = bin2hex(random_bytes(16));

        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . self::TABLE . ' SET status = ?, claim_token = ?, updated_at = NOW() '
            . 'WHERE direction = ? AND status = ? AND attempts < ? '
            . 'ORDER BY created_at LIMIT ' . (int)$limit,
            [self::STATUS_IN_FLIGHT, $claimToken, $direction, self::STATUS_PENDING, $maxAttempts]
        );

        return QueryUtils::fetchRecords(
            'SELECT * FROM ' . self::TABLE . ' WHERE claim_token = ? ORDER BY created_at',
            [$claimToken]
        );
    }

    /**
     * @param array<string, mixed> $response
     */
    public function markComplete(int $queueId, array $response = []): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . self::TABLE . ' SET status = ?, attempts = attempts + 1, '
            . 'last_error = NULL, response = ?, claim_token = NULL, completed_at = NOW(), updated_at = NOW() '
            . 'WHERE id = ?',
            [self::STATUS_COMPLETE, json_encode($response, JSON_THROW_ON_ERROR), $queueId]
        );
    }

    /**
     * A retryable failure goes back to pending; anything else is parked for
     * support so it stops burning attempts.
     *
     * @return string the status the row was left in
     */
    public function markFailed(int $queueId, string $error, bool $retryable, int $maxAttempts): string
    {
        $attempts = (int)QueryUtils::fetchSingleValue(
            'SELECT attempts FROM ' . self::TABLE . ' WHERE id = ?',
            'attempts',
            [$queueId]
        );

        $nextStatus = ($retryable && ($attempts + 1) < $maxAttempts)
            ? self::STATUS_PENDING
            : self::STATUS_PARKED;

        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . self::TABLE . ' SET status = ?, attempts = attempts + 1, '
            . 'last_error = ?, claim_token = NULL, updated_at = NOW() WHERE id = ?',
            [$nextStatus, mb_substr($error, 0, 500), $queueId]
        );

        return $nextStatus;
    }

    /**
     * Release rows a crashed worker left claimed.
     */
    public function releaseStale(int $olderThanMinutes = 15): int
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . self::TABLE . ' SET status = ?, claim_token = NULL, updated_at = NOW() '
            . 'WHERE status = ? AND updated_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [self::STATUS_PENDING, self::STATUS_IN_FLIGHT, $olderThanMinutes]
        );

        return (int)QueryUtils::fetchSingleValue('SELECT ROW_COUNT() AS c', 'c', []);
    }

    /**
     * One page of the log, newest first. $offset is how many newer rows to
     * skip, so the log screen can page through all of it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listRecent(int $limit = 100, ?int $patientId = null, int $offset = 0): array
    {
        $sql = 'SELECT id, direction, message_type, logicoy_message_id, patient_id, facility_id, '
            . 'provider_user_id, status, attempts, last_error, created_at, updated_at, completed_at '
            . 'FROM ' . self::TABLE;
        $binds = [];

        if ($patientId !== null) {
            $sql .= ' WHERE patient_id = ?';
            $binds[] = $patientId;
        }

        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . (int)$limit . ' OFFSET ' . max(0, $offset);

        return QueryUtils::fetchRecords($sql, $binds);
    }

    /**
     * How many rows listRecent() would page through.
     */
    public function countRecent(?int $patientId = null): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM ' . self::TABLE;
        $binds = [];

        if ($patientId !== null) {
            $sql .= ' WHERE patient_id = ?';
            $binds[] = $patientId;
        }

        return (int)QueryUtils::fetchSingleValue($sql, 'c', $binds);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(int $queueId): array
    {
        $raw = QueryUtils::fetchSingleValue(
            'SELECT payload FROM ' . self::TABLE . ' WHERE id = ?',
            'payload',
            [$queueId]
        );

        $decoded = json_decode((string)$raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
