<?php

/**
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Identity\IdmeMfaSessionStoreInterface;

class InMemoryIdmeMfaSessionStore implements IdmeMfaSessionStoreInterface
{
    /** @var array<string, array<string, mixed>> "facility:provider" => row */
    public array $rows = [];

    /** Every write, in order, so a test can prove how many there were. */
    public array $writes = [];

    public function find(int $facilityId, int $providerUserId): ?array
    {
        return $this->rows[$facilityId . ':' . $providerUserId] ?? null;
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
        $this->writes[] = 'started:' . $sessionUuid;
        $this->rows[$facilityId . ':' . $providerUserId] = [
            'facility_id' => $facilityId,
            'provider_user_id' => $providerUserId,
            'mfa_method_uuid' => $method['uuid'] ?? '',
            'mfa_method_name' => $method['name'] ?? '',
            'mfa_method_option' => $method['option'] ?? '',
            'mfa_method_label' => $method['label'] ?? '',
            'session_uuid' => $sessionUuid,
            'status' => $status,
            'expires_at' => $expiresAt,
            'status_message' => $message,
            'accepted_at' => null,
        ];
    }

    public function recordOutcome(
        int $facilityId,
        int $providerUserId,
        string $sessionUuid,
        string $status,
        ?string $expiresAt,
        string $message
    ): void {
        $this->writes[] = 'outcome:' . $sessionUuid . ':' . $status;
        $key = $facilityId . ':' . $providerUserId;
        if (($this->rows[$key]['session_uuid'] ?? null) !== $sessionUuid) {
            return;
        }
        $this->rows[$key]['status'] = $status;
        $this->rows[$key]['expires_at'] = $expiresAt ?? $this->rows[$key]['expires_at'];
        $this->rows[$key]['status_message'] = $message;
        if ($status === 'ACCEPTED' && empty($this->rows[$key]['accepted_at'])) {
            $this->rows[$key]['accepted_at'] = gmdate('Y-m-d H:i:s');
        }
    }
}
