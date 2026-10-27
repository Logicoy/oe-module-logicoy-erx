<?php

/**
 * Stored ID.me verification outcomes, one per prescriber registration.
 *
 * Keyed on facility and prescriber because that is what the backend keys the
 * clinician on: a prescriber registered at two facilities is two clinicians.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Identity;

use OpenEMR\Common\Database\QueryUtils;

class IdmeVerificationStore implements IdmeVerificationStoreInterface
{
    public const TABLE = 'logicoy_erx_idme_verification';

    public function find(int $facilityId, int $providerUserId): ?array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT * FROM ' . self::TABLE . ' WHERE facility_id = ? AND provider_user_id = ? LIMIT 1',
            [$facilityId, $providerUserId]
        );

        return $rows[0] ?? null;
    }

    /**
     * Every stored verification at a facility, for the admin screen.
     *
     * @return array<int, array<string, mixed>> keyed by provider user id
     */
    public function listForFacility(int $facilityId): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT * FROM ' . self::TABLE . ' WHERE facility_id = ?',
            [$facilityId]
        );

        $byProvider = [];
        foreach ($rows as $row) {
            $byProvider[(int)$row['provider_user_id']] = $row;
        }

        return $byProvider;
    }

    /**
     * The email this prescriber used last, at any facility, so a second
     * registration does not have to retype it.
     */
    public function lastEmailFor(int $providerUserId): string
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT idme_email FROM ' . self::TABLE
            . " WHERE provider_user_id = ? AND idme_email <> '' ORDER BY updated_at DESC LIMIT 1",
            [$providerUserId]
        );

        return (string)($rows[0]['idme_email'] ?? '');
    }

    public function emailHeldByAnother(string $email, int $providerUserId): bool
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT 1 AS held FROM ' . self::TABLE
            . ' WHERE LOWER(idme_email) = LOWER(?) AND provider_user_id <> ? LIMIT 1',
            [$email, $providerUserId]
        );

        return $rows !== [];
    }

    public function recordInitiated(int $facilityId, int $providerUserId, string $email): void
    {
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO ' . self::TABLE . ' SET facility_id = ?, provider_user_id = ?, idme_email = ?, '
            . "verification_status = 'PENDING', initiated_at = NOW(), created_at = NOW(), updated_at = NOW() "
            . 'ON DUPLICATE KEY UPDATE '
            . 'idme_email = VALUES(idme_email), '
            // A prescriber re-verifying while still VERIFIED keeps that status
            // until check-status reports the new one; anything else is pending.
            . "verification_status = IF(verification_status = 'VERIFIED' AND idme_email = VALUES(idme_email), "
            . "verification_status, 'PENDING'), "
            . 'initiated_at = NOW(), updated_at = NOW()',
            [$facilityId, $providerUserId, $email]
        );
    }

    public function recordStatus(
        int $facilityId,
        int $providerUserId,
        string $email,
        string $status,
        ?string $expiresOn,
        string $message
    ): void {
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO ' . self::TABLE . ' SET facility_id = ?, provider_user_id = ?, idme_email = ?, '
            . 'verification_status = ?, verification_exp_date = ?, status_message = ?, '
            . 'checked_at = NOW(), created_at = NOW(), updated_at = NOW() '
            . 'ON DUPLICATE KEY UPDATE '
            . 'idme_email = VALUES(idme_email), '
            . 'verification_status = VALUES(verification_status), '
            . 'verification_exp_date = VALUES(verification_exp_date), '
            . 'status_message = VALUES(status_message), '
            . 'checked_at = NOW(), updated_at = NOW()',
            [$facilityId, $providerUserId, $email, $status, $expiresOn, substr($message, 0, 500)]
        );
    }
}
