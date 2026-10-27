<?php

/**
 * Stored ID.me MFA sessions, one per prescriber registration.
 *
 * Keyed on facility and prescriber like the verification record, because the
 * backend keys the clinician on practice and service location. Every write is
 * an upsert on that key, so a replayed start, complete or status check lands
 * on the same row.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Identity;

use OpenEMR\Common\Database\QueryUtils;

class IdmeMfaSessionStore implements IdmeMfaSessionStoreInterface
{
    public const TABLE = 'logicoy_erx_idme_mfa_session';

    public function find(int $facilityId, int $providerUserId): ?array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT * FROM ' . self::TABLE . ' WHERE facility_id = ? AND provider_user_id = ? LIMIT 1',
            [$facilityId, $providerUserId]
        );

        return $rows[0] ?? null;
    }

    public function recordStarted(
        int $facilityId,
        int $providerUserId,
        array $method,
        string $sessionUuid,
        string $status,
        ?string $expiresAt,
        string $message
    ): void {
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO ' . self::TABLE . ' SET facility_id = ?, provider_user_id = ?, '
            . 'mfa_method_uuid = ?, mfa_method_name = ?, mfa_method_option = ?, mfa_method_label = ?, '
            . 'session_uuid = ?, status = ?, expires_at = ?, status_message = ?, '
            . 'started_at = NOW(), accepted_at = NULL, checked_at = NULL, created_at = NOW(), updated_at = NOW() '
            . 'ON DUPLICATE KEY UPDATE '
            . 'mfa_method_uuid = VALUES(mfa_method_uuid), '
            . 'mfa_method_name = VALUES(mfa_method_name), '
            . 'mfa_method_option = VALUES(mfa_method_option), '
            . 'mfa_method_label = VALUES(mfa_method_label), '
            . 'session_uuid = VALUES(session_uuid), '
            . 'status = VALUES(status), '
            . 'expires_at = VALUES(expires_at), '
            . 'status_message = VALUES(status_message), '
            . 'started_at = NOW(), accepted_at = NULL, checked_at = NULL, updated_at = NOW()',
            [
                $facilityId,
                $providerUserId,
                substr((string)($method['uuid'] ?? ''), 0, 64),
                substr((string)($method['name'] ?? ''), 0, 64),
                substr((string)($method['option'] ?? ''), 0, 64),
                substr((string)($method['label'] ?? ''), 0, 128),
                substr($sessionUuid, 0, 64),
                $status,
                $expiresAt,
                substr($message, 0, 500),
            ]
        );
    }

    public function recordOutcome(
        int $facilityId,
        int $providerUserId,
        string $sessionUuid,
        string $status,
        ?string $expiresAt,
        string $message
    ): void {
        // An outcome belongs to the session it was asked about. If the stored
        // session has moved on (a restart raced this check), the stale answer
        // is dropped rather than written over the newer session.
        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . self::TABLE . ' SET status = ?, '
            // The expiry the backend gives on completion is authoritative; a
            // blank one keeps the time given at start.
            . 'expires_at = COALESCE(?, expires_at), '
            . 'status_message = ?, '
            . "accepted_at = IF(? = 'ACCEPTED', COALESCE(accepted_at, NOW()), accepted_at), "
            . 'checked_at = NOW(), updated_at = NOW() '
            . 'WHERE facility_id = ? AND provider_user_id = ? AND session_uuid = ?',
            [$status, $expiresAt, substr($message, 0, 500), $status, $facilityId, $providerUserId, $sessionUuid]
        );
    }
}
