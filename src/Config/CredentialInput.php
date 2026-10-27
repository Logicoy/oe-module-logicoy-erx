<?php

/**
 * Validates and normalizes a credential before it reaches the database.
 *
 * Kept free of database and filesystem access so the rules are unit testable —
 * the CLI reads the key file and hands the PEM in.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Config;

class CredentialInput
{
    /** @var array<int, string> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $normalized = [];

    /**
     * @param array<string, mixed> $input
     */
    public function __construct(array $input)
    {
        $this->validate($input);
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<int, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * The payload for CredentialStore::save().
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->normalized;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function validate(array $input): void
    {
        $scope = (string)($input['scope_type'] ?? CredentialStore::SCOPE_PRACTICE);
        if (!in_array($scope, [CredentialStore::SCOPE_PRACTICE, CredentialStore::SCOPE_PROVIDER], true)) {
            $this->errors[] = 'scope must be either "practice" or "provider"';
        }

        $facilityId = (int)($input['facility_id'] ?? 0);
        if ($facilityId <= 0) {
            $this->errors[] = 'facility is required and must be a positive id';
        }

        // 0 is the "no provider" sentinel; see CredentialStore::NO_PROVIDER.
        $providerUserId = isset($input['provider_user_id']) && $input['provider_user_id'] !== ''
            ? (int)$input['provider_user_id']
            : CredentialStore::NO_PROVIDER;

        if ($scope === CredentialStore::SCOPE_PROVIDER && $providerUserId <= 0) {
            $this->errors[] = 'a provider scoped credential needs a provider user id';
        }
        if ($scope === CredentialStore::SCOPE_PRACTICE && $providerUserId !== CredentialStore::NO_PROVIDER) {
            $this->errors[] = 'a practice scoped credential must not carry a provider user id';
        }

        $clientId = trim((string)($input['client_id'] ?? ''));
        if ($clientId === '') {
            $this->errors[] = 'client id is required';
        }

        $signingKey = (string)($input['signing_key'] ?? '');
        if ($signingKey !== '' && !$this->isUsablePrivateKey($signingKey)) {
            $this->errors[] = 'the private key could not be parsed; upload the PEM file from the LogiCoy Vendor portal';
        }

        // None of the eRx uuids are typed in — the backend mints them during
        // onboarding, so a credential is saved before any of them exist. This
        // used to be required, which forced callers to invent a placeholder,
        // and the placeholder then read as "already registered" and suppressed
        // the registration call entirely.
        $practiceUuid = self::normalizeProvisionedUuid($input['erx_practice_uuid'] ?? null);

        if (!$this->isValid()) {
            return;
        }

        $this->normalized = [
            'scope_type' => $scope,
            'facility_id' => $facilityId,
            'provider_user_id' => $providerUserId,
            'client_id' => $clientId,
            'signing_key' => $signingKey,
            'jwt_kid' => $this->nullIfBlank($input['jwt_kid'] ?? null),
            'erx_client_registration_uuid' => $this->nullIfBlank($input['erx_client_registration_uuid'] ?? null),
            'erx_practice_uuid' => $practiceUuid,
            'erx_location_uuid' => self::normalizeProvisionedUuid($input['erx_location_uuid'] ?? null),
            'erx_clinician_user_uuid' => $this->nullIfBlank($input['erx_clinician_user_uuid'] ?? null),
            'erx_clinician_user_pk_uuid' => $this->nullIfBlank($input['erx_clinician_user_pk_uuid'] ?? null),
            'active' => isset($input['active']) ? (int)(bool)$input['active'] : 1,
        ];
    }

    /**
     * An unprovisioned identifier is null, never a placeholder string.
     *
     * 'pending' is still recognised so rows written by earlier versions do not
     * stay stuck reporting themselves as registered.
     */
    public static function normalizeProvisionedUuid(mixed $value): ?string
    {
        $uuid = trim((string)($value ?? ''));

        return ($uuid === '' || strcasecmp($uuid, 'pending') === 0) ? null : $uuid;
    }

    /**
     * Reject a key here rather than at 3am when the queue worker cannot sign.
     */
    private function isUsablePrivateKey(string $pem): bool
    {
        if (!str_contains($pem, 'PRIVATE KEY')) {
            return false;
        }

        $key = @openssl_pkey_get_private($pem);
        if ($key === false) {
            return false;
        }

        $details = @openssl_pkey_get_details($key);

        // The gateway signs assertions with RS256, so the key has to be RSA.
        return is_array($details) && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA;
    }

    /**
     * @param mixed $value
     */
    private function nullIfBlank($value): ?string
    {
        $trimmed = trim((string)($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }
}
