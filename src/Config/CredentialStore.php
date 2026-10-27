<?php

/**
 * Per-practice and per-provider LogiCoy credentials.
 *
 * The signing key is encrypted at rest with OpenEMR's CryptoGen and is never
 * returned by any list or log path — only getSigningKey() decrypts it.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Config;

use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Common\Database\QueryUtils;

class CredentialStore
{
    public const TABLE = 'logicoy_erx_credentials';

    public const SCOPE_PRACTICE = 'practice';
    public const SCOPE_PROVIDER = 'provider';

    private CryptoGen $cryptoGen;
    private ?GlobalConfig $globalConfig;
    private KeyStore $keyStore;

    public function __construct(
        ?CryptoGen $cryptoGen = null,
        ?GlobalConfig $globalConfig = null,
        ?KeyStore $keyStore = null
    ) {
        $this->cryptoGen = $cryptoGen ?? new CryptoGen();
        $this->globalConfig = $globalConfig;
        $this->keyStore = $keyStore ?? new KeyStore();
    }

    /**
     * Practice level identifiers configured under Globals fill in anything a
     * credential row leaves blank. Most practices configure once in Globals and
     * only use the credentials screen to upload the signing key; a per-provider
     * row can still override any field.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withGlobalDefaults(array $row): array
    {
        $config = $this->globalConfig ?? new GlobalConfig($GLOBALS);

        foreach ($config->getErxIdentifiers() as $field => $value) {
            if ($value !== '' && trim((string)($row[$field] ?? '')) === '') {
                $row[$field] = $value;
            }
        }

        return $row;
    }

    public const NO_PROVIDER = 0;

    /**
     * Credentials for a facility, optionally narrowed to one provider.
     *
     * A provider row wins over the practice row so a prescriber can carry their
     * own client id, falling back to the practice credential when they do not.
     *
     * @return array<string, mixed>|null
     */
    public function getCredential(int $facilityId, ?int $providerUserId = null): ?array
    {
        if ($providerUserId !== null && $providerUserId !== self::NO_PROVIDER) {
            $sql = 'SELECT * FROM ' . self::TABLE
                . ' WHERE scope_type = ? AND facility_id = ? AND provider_user_id = ? AND active = 1 LIMIT 1';
            $row = QueryUtils::fetchRecords($sql, [self::SCOPE_PROVIDER, $facilityId, $providerUserId]);
            if (!empty($row[0])) {
                return $this->withGlobalDefaults($row[0]);
            }
        }

        $sql = 'SELECT * FROM ' . self::TABLE
            . ' WHERE scope_type = ? AND facility_id = ? AND provider_user_id = ? AND active = 1 LIMIT 1';
        $row = QueryUtils::fetchRecords($sql, [self::SCOPE_PRACTICE, $facilityId, self::NO_PROVIDER]);

        return isset($row[0]) ? $this->withGlobalDefaults($row[0]) : null;
    }

    /**
     * Active practice credentials under one LogiCoy client registration.
     *
     * A pushed notification names its client registration, not a facility.
     * The registration uuid is usually configured once in Globals rather than
     * per row, so it is matched after the global defaults are applied.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPracticeCredentialsForRegistration(string $registrationUuid): array
    {
        $registrationUuid = trim($registrationUuid);
        if ($registrationUuid === '') {
            return [];
        }

        $rows = QueryUtils::fetchRecords(
            'SELECT * FROM ' . self::TABLE . ' WHERE scope_type = ? AND provider_user_id = ? AND active = 1'
            . ' ORDER BY facility_id',
            [self::SCOPE_PRACTICE, self::NO_PROVIDER]
        );

        $matches = [];
        foreach ($rows as $row) {
            $row = $this->withGlobalDefaults($row);
            if (trim((string)($row['erx_client_registration_uuid'] ?? '')) === $registrationUuid) {
                $matches[] = $row;
            }
        }

        return $matches;
    }

    /**
     * The clinician identifier the draft endpoints ask for.
     *
     * A prescriber has one identifier, not two. /v1/clinician/add returns it as
     * erxClinicianUserPKUuid; the draft endpoints name the same value
     * erxClinicianUserUuid. LogiCoy's own SDK flow resolves it this way round
     * too, so a row that only carries the PK uuid — which is every row the
     * credentials screen writes — still has what the draft endpoints need.
     *
     * @param array<string, mixed> $credential
     */
    public static function clinicianUuid(array $credential): string
    {
        return trim((string)($credential['erx_clinician_user_uuid'] ?? ''))
            ?: trim((string)($credential['erx_clinician_user_pk_uuid'] ?? ''));
    }

    /**
     * The same identifier under the name the send and search endpoints use.
     *
     * @param array<string, mixed> $credential
     */
    public static function clinicianPkUuid(array $credential): string
    {
        return trim((string)($credential['erx_clinician_user_pk_uuid'] ?? ''))
            ?: trim((string)($credential['erx_clinician_user_uuid'] ?? ''));
    }

    /**
     * Facilities this user can prescribe under, for the screen's facility picker.
     *
     * Mirrors getCredential()'s precedence: the user's own provider row for a
     * facility, or the practice row when a single prescriber practice loaded its
     * clinician identifier by hand. A facility whose credential carries no
     * clinician is left out — picking it would only produce the "not registered"
     * banner again.
     *
     * @return array<int, array{facility_id: int, name: string}>
     */
    public function listPrescribingFacilities(int $providerUserId): array
    {
        $sql = 'SELECT c.facility_id, c.scope_type, f.name FROM ' . self::TABLE . ' c'
            . ' JOIN facility f ON f.id = c.facility_id'
            . ' WHERE c.active = 1'
            . ' AND (c.scope_type = ? OR (c.scope_type = ? AND c.provider_user_id = ?))'
            . " AND (COALESCE(c.erx_clinician_user_uuid, '') <> ''"
            . " OR COALESCE(c.erx_clinician_user_pk_uuid, '') <> '')"
            . ' ORDER BY f.name';

        $rows = QueryUtils::fetchRecords(
            $sql,
            [self::SCOPE_PRACTICE, self::SCOPE_PROVIDER, $providerUserId]
        );

        // A provider row and a practice row can both exist for one facility; the
        // picker offers the facility once.
        $facilities = [];
        foreach ($rows as $row) {
            $facilities[(int)$row['facility_id']] = [
                'facility_id' => (int)$row['facility_id'],
                'name' => (string)$row['name'],
            ];
        }

        return array_values($facilities);
    }

    /**
     * Decrypt the PEM signing key for a credential row.
     *
     * Kept separate from getCredential() so callers that only need identifiers
     * never hold the key in memory.
     */
    public function getSigningKey(int $credentialId): ?string
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT scope_type, facility_id, signing_key_path, signing_key_encrypted FROM '
            . self::TABLE . ' WHERE id = ?',
            [$credentialId]
        );
        $row = $rows[0] ?? null;

        if ($row === null) {
            return null;
        }

        // A provider row carries that prescriber's own identifiers but rarely a
        // key of its own — registering a prescriber creates one with no key at
        // all. Fall back to the practice key for the same facility so
        // registering a prescriber does not silently break prescribing for
        // them. A provider row that does carry its own key still wins.
        if (
            $row['scope_type'] === self::SCOPE_PROVIDER
            && trim((string)($row['signing_key_path'] ?? '')) === ''
            && empty($row['signing_key_encrypted'])
        ) {
            $practice = QueryUtils::fetchRecords(
                'SELECT signing_key_path, signing_key_encrypted FROM ' . self::TABLE
                . ' WHERE scope_type = ? AND facility_id = ? AND provider_user_id = ? AND active = 1 LIMIT 1',
                [self::SCOPE_PRACTICE, (int)$row['facility_id'], self::NO_PROVIDER]
            );
            if (!empty($practice[0])) {
                $row = $practice[0];
            }
        }

        // Precedence: the credential's own key file, then a path configured
        // under Globals, then the encrypted column left over from before keys
        // moved to disk.
        $path = (string)($row['signing_key_path'] ?? '');
        if ($path === '') {
            $path = ($this->globalConfig ?? new GlobalConfig($GLOBALS))->getErxSigningKeyPath();
        }

        if ($path !== '') {
            return $this->keyStore->read($path);
        }

        if (empty($row['signing_key_encrypted'])) {
            return null;
        }

        $pem = $this->cryptoGen->decryptStandard($row['signing_key_encrypted']);

        return $pem === false ? null : $pem;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(array $data): int
    {
        $scope = (string)($data['scope_type'] ?? self::SCOPE_PRACTICE);
        $facilityId = (int)($data['facility_id'] ?? 0);
        $providerUserId = (int)($data['provider_user_id'] ?? self::NO_PROVIDER);

        $signingKey = (string)($data['signing_key'] ?? '');
        $keyPath = trim((string)($data['signing_key_path'] ?? ''));

        if ($signingKey !== '') {
            // New key material: write it to disk and keep only the path.
            $keyPath = $this->keyStore->store(
                $signingKey,
                $scope,
                $facilityId,
                $providerUserId ?: null
            );
        } elseif ($keyPath !== '') {
            // An operator managed path. Refuse anything web reachable or loose.
            $problems = $this->keyStore->validatePath($keyPath);
            if ($problems !== []) {
                throw new KeyStoreException(implode(' ', $problems));
            }
        }

        $sql = 'INSERT INTO ' . self::TABLE . ' SET '
            . 'scope_type = ?, facility_id = ?, provider_user_id = ?, client_id = ?, '
            . 'signing_key_path = ?, jwt_kid = ?, erx_client_registration_uuid = ?, '
            . 'erx_practice_uuid = ?, erx_location_uuid = ?, erx_clinician_user_uuid = ?, '
            . 'erx_clinician_user_pk_uuid = ?, active = ?, created_at = NOW(), updated_at = NOW() '
            . 'ON DUPLICATE KEY UPDATE '
            . 'client_id = VALUES(client_id), '
            // An empty path on update means "leave the stored key alone".
            . 'signing_key_path = IF(VALUES(signing_key_path) = \'\' OR VALUES(signing_key_path) IS NULL, '
            . 'signing_key_path, VALUES(signing_key_path)), '
            . 'jwt_kid = VALUES(jwt_kid), '
            . 'erx_client_registration_uuid = VALUES(erx_client_registration_uuid), '
            . 'erx_practice_uuid = VALUES(erx_practice_uuid), '
            . 'erx_location_uuid = VALUES(erx_location_uuid), '
            . 'erx_clinician_user_uuid = VALUES(erx_clinician_user_uuid), '
            . 'erx_clinician_user_pk_uuid = VALUES(erx_clinician_user_pk_uuid), '
            . 'active = VALUES(active), updated_at = NOW()';

        $binds = [
            $data['scope_type'] ?? self::SCOPE_PRACTICE,
            (int)($data['facility_id'] ?? 0),
            (int)($data['provider_user_id'] ?? self::NO_PROVIDER),
            $data['client_id'] ?? '',
            $keyPath,
            $data['jwt_kid'] ?? null,
            $data['erx_client_registration_uuid'] ?? null,
            $data['erx_practice_uuid'] ?? null,
            $data['erx_location_uuid'] ?? null,
            $data['erx_clinician_user_uuid'] ?? null,
            $data['erx_clinician_user_pk_uuid'] ?? null,
            isset($data['active']) ? (int)$data['active'] : 1,
        ];

        return (int)QueryUtils::sqlInsert($sql, $binds);
    }

    /**
     * Facilities with an active practice credential: the ones the background
     * sync polls for pharmacy notifications.
     *
     * @return int[]
     */
    public function listPollingFacilityIds(): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT DISTINCT facility_id FROM ' . self::TABLE
            . ' WHERE scope_type = ? AND provider_user_id = ? AND active = 1 ORDER BY facility_id',
            [self::SCOPE_PRACTICE, self::NO_PROVIDER]
        );

        return array_map(static fn(array $r): int => (int)$r['facility_id'], $rows);
    }

    /**
     * Every credential, with the key column omitted so this is safe to render.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listCredentials(): array
    {
        $sql = 'SELECT id, scope_type, facility_id, provider_user_id, client_id, jwt_kid, '
            . 'erx_practice_uuid, erx_location_uuid, erx_clinician_user_uuid, '
            . 'erx_clinician_user_pk_uuid, active, created_at, updated_at, '
            . 'IF((signing_key_path IS NULL OR signing_key_path = \'\') '
            . 'AND (signing_key_encrypted IS NULL OR signing_key_encrypted = \'\'), 0, 1) AS has_signing_key, '
            . 'signing_key_path '
            . 'FROM ' . self::TABLE . ' ORDER BY facility_id, scope_type';

        return QueryUtils::fetchRecords($sql, []);
    }

    public function deactivate(int $credentialId): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE ' . self::TABLE . ' SET active = 0, updated_at = NOW() WHERE id = ?',
            [$credentialId]
        );
    }
}
