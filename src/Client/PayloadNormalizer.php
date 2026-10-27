<?php

/**
 * Shapes outbound fields to what the LogiCoy backend will accept.
 *
 * The backend's contract is omit-or-valid, not blank-is-absent. Registering a
 * facility with no fax on file sent `"faxNum": ""` and failed the whole call
 * with "faxNum cannot be empty if present" / "Fax number must be exactly 10
 * digits", so a field OpenEMR has no value for must not appear in the payload
 * at all.
 *
 * Kept free of database access so the rules are unit testable, in the same
 * shape as CredentialInput.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Client;

class PayloadNormalizer
{
    /**
     * Drops the keys the backend has nothing to validate.
     *
     * A genuinely required field that is missing comes back named by the
     * backend, which is a better error than one we would invent here.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function withoutBlanks(array $payload): array
    {
        return array_filter(
            $payload,
            static fn($value): bool => $value !== null && $value !== '' && $value !== []
        );
    }

    /**
     * A phone or fax the backend will accept, or null to leave the key out.
     *
     * Nothing on file drops the field. Something on file that cannot be read as
     * ten digits is raised instead: dropping it would resurface as
     * "phone is required", which points the admin at the wrong problem.
     *
     * @param mixed $value
     * @throws BackendException
     */
    public static function phoneNumber($value, string $label): ?string
    {
        $raw = trim((string)($value ?? ''));
        if ($raw === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $raw) ?? '';

        // OpenEMR's phone fields are free text, so a country code or a trunk
        // prefix in front of the ten digits is common.
        if (strlen($digits) > 10) {
            $digits = substr($digits, -10);
        }

        if (strlen($digits) !== 10) {
            throw new BackendException(
                'The ' . $label . ' is not a 10 digit number. Correct it in OpenEMR and register again.'
            );
        }

        return $digits;
    }
}
