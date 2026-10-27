<?php

/**
 * Per-provider eRx profile: date of birth, prescribing type, DEA expiration
 * and state, and the last four of the SSN.
 *
 * These sit in a module table rather than as columns on `users` — the module
 * does not change core schema. The SSN fragment is encrypted at rest with
 * CryptoGen and is never returned by get() or by any list path; only
 * getSsnLast4() decrypts it.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Config;

use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Common\Database\QueryUtils;

class ProviderProfileStore
{
    public const TABLE = 'logicoy_erx_provider_profile';

    /** Placeholder rendered back into the form for a stored SSN fragment. */
    public const SSN_MASK = '••••';

    private CryptoGen $cryptoGen;

    public function __construct(?CryptoGen $cryptoGen = null)
    {
        $this->cryptoGen = $cryptoGen ?? new CryptoGen();
    }

    /**
     * The profile for a user, with the SSN fragment reduced to a boolean so
     * this is safe to render and safe to log.
     *
     * @return array<string, mixed> Always populated; an unsaved user gets the
     *                              same shape with empty values.
     */
    public function get(int $userId): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT user_id, dob, gender, prescribing_type, dea_expiration_date, dea_state, '
            . 'IF(ssn_last4_encrypted IS NULL OR ssn_last4_encrypted = \'\', 0, 1) AS has_ssn_last4 '
            . 'FROM ' . self::TABLE . ' WHERE user_id = ? LIMIT 1',
            [$userId]
        );

        $row = $rows[0] ?? [];

        return [
            'user_id' => $userId,
            'dob' => $row['dob'] ?? null,
            'gender' => $row['gender'] ?? null,
            'prescribing_type' => $row['prescribing_type'] ?? ProviderProfileInput::TYPE_ERX,
            'dea_expiration_date' => $row['dea_expiration_date'] ?? null,
            'dea_state' => $row['dea_state'] ?? null,
            'has_ssn_last4' => (int)($row['has_ssn_last4'] ?? 0) === 1,
        ];
    }

    /**
     * Kept separate from get() so the screens that only need to show a profile
     * never hold the SSN fragment in memory.
     */
    public function getSsnLast4(int $userId): ?string
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT ssn_last4_encrypted FROM ' . self::TABLE . ' WHERE user_id = ? LIMIT 1',
            [$userId]
        );

        $stored = $rows[0]['ssn_last4_encrypted'] ?? '';
        if (empty($stored)) {
            return null;
        }

        $plain = $this->cryptoGen->decryptStandard($stored);

        return ($plain === false || $plain === '') ? null : $plain;
    }

    /**
     * Upsert one user's profile.
     *
     * A null 'ssn_last4' leaves the stored fragment alone, so re-saving the
     * user form without retyping it does not wipe it. Pass an empty string to
     * clear it deliberately.
     *
     * @param array<string, mixed> $data As returned by ProviderProfileInput::toArray()
     */
    public function save(int $userId, array $data): void
    {
        $ssn = $data['ssn_last4'] ?? null;
        $encrypted = null;
        if ($ssn !== null && $ssn !== '') {
            $encrypted = $this->cryptoGen->encryptStandard((string)$ssn);
        }
        $clearSsn = ($ssn === '');

        $sql = 'INSERT INTO ' . self::TABLE . ' SET '
            . 'user_id = ?, dob = ?, gender = ?, prescribing_type = ?, dea_expiration_date = ?, dea_state = ?, '
            . 'ssn_last4_encrypted = ?, created_at = NOW(), updated_at = NOW() '
            . 'ON DUPLICATE KEY UPDATE '
            . 'dob = VALUES(dob), '
            . 'gender = VALUES(gender), '
            . 'prescribing_type = VALUES(prescribing_type), '
            . 'dea_expiration_date = VALUES(dea_expiration_date), '
            . 'dea_state = VALUES(dea_state), '
            // Nothing retyped means keep what is stored; ? = 1 is the explicit clear.
            . 'ssn_last4_encrypted = IF(VALUES(ssn_last4_encrypted) IS NOT NULL, VALUES(ssn_last4_encrypted), '
            . 'IF(? = 1, NULL, ssn_last4_encrypted)), '
            . 'updated_at = NOW()';

        QueryUtils::sqlStatementThrowException($sql, [
            $userId,
            $data['dob'] ?? null,
            $data['gender'] ?? null,
            $data['prescribing_type'] ?? ProviderProfileInput::TYPE_ERX,
            $data['dea_expiration_date'] ?? null,
            $data['dea_state'] ?? null,
            $encrypted,
            $clearSsn ? 1 : 0,
        ]);
    }

    public function isEpcs(int $userId): bool
    {
        return ($this->get($userId)['prescribing_type'] ?? '') === ProviderProfileInput::TYPE_EPCS;
    }
}
