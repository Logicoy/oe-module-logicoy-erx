<?php

/**
 * Validates and normalizes the eRx fields collected on the OpenEMR user
 * screens before they reach the database.
 *
 * Kept free of database access so the rules are unit testable, in the same
 * shape as CredentialInput.
 *
 * These fields are saved from UserCreatedEvent / UserUpdatedEvent, which fire
 * *after* core has already written the user, so there is nothing left to abort.
 * Each field is therefore validated on its own: a bad value is dropped rather
 * than taking the rest of the profile down with it, and the caller reports what
 * was dropped. Error text names the field only — never the value, which is PII.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Config;

class ProviderProfileInput
{
    /** Registered at the backend as NON_EPCS; controlled substances blocked. */
    public const TYPE_ERX = 'ERX';

    /** Registered at the backend as EPCS; needs the identity proofing fields. */
    public const TYPE_EPCS = 'EPCS';

    /** Form field prefix, so nothing here can collide with a core POST key. */
    public const FIELD_PREFIX = 'logicoy_erx_';

    /** What /v1/clinician/add accepts for gender, in OpenEMR's own order. */
    public const GENDERS = ['M', 'F', 'O'];

    /** @var array<int, string> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $normalized = [];

    /**
     * @param array<string, mixed> $input Raw POST, or a plain field => value map.
     */
    public function __construct(array $input)
    {
        $this->validate($this->strip($input));
    }

    /**
     * True when every supplied value was usable. A profile with nothing filled
     * in is valid — none of these fields are mandatory for plain eRx.
     */
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
     * The payload for ProviderProfileStore::save(). Fields that failed
     * validation come back as null.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->normalized;
    }

    /**
     * True when the POST carried none of our fields at all — a form that was
     * rendered before this version, or a save path that never showed them.
     * The caller uses this to leave an existing profile untouched instead of
     * blanking it.
     *
     * @param array<string, mixed> $input
     */
    public static function isAbsent(array $input): bool
    {
        foreach (array_keys(self::fields()) as $field) {
            if (array_key_exists(self::FIELD_PREFIX . $field, $input)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every field this class reads, minus the prefix.
     *
     * @return array<string, string> field name => label
     */
    public static function fields(): array
    {
        return [
            'dob' => 'Date of Birth',
            'gender' => 'Gender',
            'prescribing_type' => 'eRx Prescribing Type',
            'dea_expiration_date' => 'DEA Expiration Date',
            'dea_state' => 'DEA State',
            'ssn_last4' => 'SSN (last 4)',
            'ssn_last4_clear' => 'Remove stored SSN',
        ];
    }

    /**
     * Accepts either prefixed POST keys or bare field names.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function strip(array $input): array
    {
        $out = [];
        foreach (array_keys(self::fields()) as $field) {
            $out[$field] = $input[self::FIELD_PREFIX . $field] ?? $input[$field] ?? null;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function validate(array $input): void
    {
        $this->normalized = [
            'dob' => $this->date($input['dob'], 'date of birth', true),
            'gender' => $this->gender($input['gender']),
            'prescribing_type' => $this->prescribingType($input['prescribing_type']),
            'dea_expiration_date' => $this->date($input['dea_expiration_date'], 'DEA expiration date', false),
            'dea_state' => $this->state($input['dea_state']),
            // '' is the deliberate clear; null means "leave what is stored".
            'ssn_last4' => !empty($input['ssn_last4_clear'])
                ? ''
                : $this->ssnLast4($input['ssn_last4']),
        ];
    }

    /**
     * @param mixed $value
     */
    private function date($value, string $label, bool $mustBePast): ?string
    {
        $raw = trim((string)($value ?? ''));
        if ($raw === '') {
            return null;
        }

        // <input type="date"> always posts ISO. Anything else is a hand edited
        // request or an older browser, and we would rather store nothing than
        // guess between 03/04 and 04/03.
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($parsed === false || $parsed->format('Y-m-d') !== $raw) {
            $this->errors[] = $label . ' must be a date in YYYY-MM-DD form';

            return null;
        }

        if ($mustBePast && $parsed > new \DateTimeImmutable('today')) {
            $this->errors[] = $label . ' cannot be in the future';

            return null;
        }

        return $raw;
    }

    /**
     * The backend takes M, F or O, and refuses MALE / FEMALE / U / X with
     * "Invalid gender type". OpenEMR's `users` table has no sex or gender
     * column, which is why this is collected here at all.
     *
     * @param mixed $value
     */
    private function gender($value): ?string
    {
        $gender = strtoupper(trim((string)($value ?? '')));
        if ($gender === '') {
            return null;
        }

        if (!in_array($gender, self::GENDERS, true)) {
            $this->errors[] = 'gender must be male, female or other';

            return null;
        }

        return $gender;
    }

    /**
     * @param mixed $value
     */
    private function prescribingType($value): string
    {
        $type = strtoupper(trim((string)($value ?? '')));
        if ($type === '') {
            return self::TYPE_ERX;
        }

        if (!in_array($type, [self::TYPE_ERX, self::TYPE_EPCS], true)) {
            $this->errors[] = 'prescribing type must be either eRx or EPCS';

            return self::TYPE_ERX;
        }

        return $type;
    }

    /**
     * @param mixed $value
     */
    private function state($value): ?string
    {
        $state = strtoupper(trim((string)($value ?? '')));
        if ($state === '') {
            return null;
        }

        if (preg_match('/^[A-Z]{2}$/', $state) !== 1) {
            $this->errors[] = 'DEA state must be a two letter state code';

            return null;
        }

        return $state;
    }

    /**
     * Exactly four digits. A full SSN is rejected rather than trimmed — the
     * module has no business holding one long enough to trim it.
     *
     * @param mixed $value
     */
    private function ssnLast4($value): ?string
    {
        $digits = trim((string)($value ?? ''));
        if ($digits === '') {
            return null;
        }

        if (preg_match('/^\d{4}$/', $digits) !== 1) {
            $this->errors[] = 'SSN last 4 must be exactly four digits';

            return null;
        }

        return $digits;
    }
}
