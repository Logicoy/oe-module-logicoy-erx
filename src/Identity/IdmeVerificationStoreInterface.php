<?php

/**
 * What the ID.me verification flow needs from its record.
 *
 * Narrow so the flow can be tested without a database.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Identity;

interface IdmeVerificationStoreInterface
{
    /**
     * The stored verification for one prescriber registration, or null when
     * none has been started.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $facilityId, int $providerUserId): ?array;

    /**
     * Another prescriber already holding this ID.me email. LogiCoy refuses a
     * shared email, and saying whose it is saves a confusing backend error.
     */
    public function emailHeldByAnother(string $email, int $providerUserId): bool;

    /**
     * A verification link was issued: the email is recorded and the status
     * goes back to PENDING until check-status says otherwise.
     */
    public function recordInitiated(int $facilityId, int $providerUserId, string $email): void;

    /**
     * What check-status answered.
     */
    public function recordStatus(
        int $facilityId,
        int $providerUserId,
        string $email,
        string $status,
        ?string $expiresOn,
        string $message
    ): void;
}
