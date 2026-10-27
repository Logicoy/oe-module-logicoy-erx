<?php

/**
 * Reads and writes the contact columns on OpenEMR's own `users` row.
 *
 * OpenEMR keeps a prescriber's phone and address on `users`, but neither user
 * screen exposes them — they are only editable under Administration, Address
 * Book. /v1/clinician/add refuses a registration without both, so the eRx block
 * surfaces them on the user screens and writes them straight back to the core
 * columns rather than keeping a second copy that could drift from the address
 * book entry.
 *
 * This is the only place in the module that writes `users`. Core code and core
 * schema are untouched; these are existing columns, set to values an admin
 * typed on a screen they were already allowed to use.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Config;

use OpenEMR\Common\Database\QueryUtils;

class UserContactStore
{
    /**
     * Our form field => the core column it maps onto.
     *
     * `phonew1` is the work number, which is the one a pharmacy should reach a
     * prescriber on; `phone` is the home number.
     *
     * @var array<string, string>
     */
    public const COLUMNS = [
        'phone' => 'phonew1',
        'street' => 'street',
        'city' => 'city',
        'state' => 'state',
        'zip' => 'zip',
    ];

    /**
     * @return array<string, string> keyed by form field, never by column
     */
    public function get(int $userId): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM users WHERE id = ? LIMIT 1',
            [$userId]
        );
        $row = $rows[0] ?? [];

        $out = [];
        foreach (self::COLUMNS as $field => $column) {
            $out[$field] = trim((string)($row[$column] ?? ''));
        }

        return $out;
    }

    /**
     * The fields are rendered pre-filled, so a blank one is the admin clearing
     * it rather than an omission — the caller decides whether the form carried
     * them at all before calling this.
     *
     * @param array<string, string> $values keyed by form field
     */
    public function save(int $userId, array $values): void
    {
        $sets = [];
        $binds = [];
        foreach (self::COLUMNS as $field => $column) {
            if (!array_key_exists($field, $values)) {
                continue;
            }
            $sets[] = $column . ' = ?';
            $binds[] = trim((string)($values[$field] ?? ''));
        }

        if ($sets === []) {
            return;
        }

        $binds[] = $userId;
        QueryUtils::sqlStatementThrowException(
            'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $binds
        );
    }
}
