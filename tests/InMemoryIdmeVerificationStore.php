<?php

/**
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Identity\IdmeVerificationStoreInterface;

class InMemoryIdmeVerificationStore implements IdmeVerificationStoreInterface
{
    /** @var array<string, array<string, mixed>> "facility:provider" => row */
    public array $rows = [];

    public function find(int $facilityId, int $providerUserId): ?array
    {
        return $this->rows[$facilityId . ':' . $providerUserId] ?? null;
    }

    public function emailHeldByAnother(string $email, int $providerUserId): bool
    {
        foreach ($this->rows as $row) {
            if (strcasecmp($row['idme_email'], $email) === 0 && $row['provider_user_id'] !== $providerUserId) {
                return true;
            }
        }

        return false;
    }

    public function recordInitiated(int $facilityId, int $providerUserId, string $email): void
    {
        $this->rows[$facilityId . ':' . $providerUserId] = [
            'facility_id' => $facilityId,
            'provider_user_id' => $providerUserId,
            'idme_email' => $email,
            'verification_status' => 'PENDING',
            'verification_exp_date' => null,
        ];
    }

    public function recordStatus(
        int $facilityId,
        int $providerUserId,
        string $email,
        string $status,
        ?string $expiresOn,
        string $message
    ): void {
        $this->rows[$facilityId . ':' . $providerUserId] = [
            'facility_id' => $facilityId,
            'provider_user_id' => $providerUserId,
            'idme_email' => $email,
            'verification_status' => $status,
            'verification_exp_date' => $expiresOn,
            'status_message' => $message,
        ];
    }
}
