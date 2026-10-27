<?php

/**
 * What the ID.me MFA flow needs from its record.
 *
 * Narrow so the flow can be tested without a database.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Identity;

interface IdmeMfaSessionStoreInterface
{
    /**
     * The current MFA session for one prescriber registration, or null when
     * none has been started.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $facilityId, int $providerUserId): ?array;

    /**
     * A session was started with the backend. Replaces whatever session the
     * prescriber had before; an accepted one they are restarting included.
     *
     * @param array{uuid: string, name: string, option: string, label: string} $method
     */
    public function recordStarted(
        int $facilityId,
        int $providerUserId,
        array $method,
        string $sessionUuid,
        string $status,
        ?string $expiresAt,
        string $message
    ): void;

    /**
     * What complete or delivery-status-check answered for the stored session.
     */
    public function recordOutcome(
        int $facilityId,
        int $providerUserId,
        string $sessionUuid,
        string $status,
        ?string $expiresAt,
        string $message
    ): void;
}
