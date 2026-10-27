<?php

/**
 * The pharmacy each draft was saved with, by name.
 *
 * The backend's draft listing does not return the pharmacy, so the screen
 * keeps the one the prescriber picked from the directory search, keyed on the
 * LogiCoy medication uuid. The uuid survives the send, so the same row names
 * the pharmacy on the sent list. Display only: the backend routes on the org
 * id it was given with the draft, never on this.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Prescription;

use OpenEMR\Common\Database\QueryUtils;

class RxPharmacyStore
{
    public const TABLE = 'logicoy_erx_rx_pharmacy';

    /**
     * Upserts on the medication uuid, so a replayed save lands on one row.
     * Nothing is written without a uuid and a name to show.
     */
    public function remember(string $medicationUuid, string $pharmacyOrgId, string $pharmacyName): void
    {
        $pharmacyName = trim($pharmacyName);
        if ($medicationUuid === '' || $pharmacyName === '') {
            return;
        }

        QueryUtils::sqlStatementThrowException(
            'INSERT INTO ' . self::TABLE . ' SET erx_medication_uuid = ?, pharmacy_org_id = ?, pharmacy_name = ?, '
            . 'created_at = NOW(), updated_at = NOW() '
            . 'ON DUPLICATE KEY UPDATE pharmacy_org_id = VALUES(pharmacy_org_id), '
            . 'pharmacy_name = VALUES(pharmacy_name), updated_at = NOW()',
            [
                substr($medicationUuid, 0, 64),
                substr($pharmacyOrgId, 0, 64),
                mb_substr($pharmacyName, 0, 255),
            ]
        );
    }

    /**
     * @param string[] $medicationUuids
     * @return array<string, string> medication uuid => pharmacy name, for
     *         those that have one
     */
    public function namesFor(array $medicationUuids): array
    {
        $medicationUuids = array_values(array_unique(array_filter($medicationUuids, 'strlen')));
        if ($medicationUuids === []) {
            return [];
        }

        $rows = QueryUtils::fetchRecords(
            'SELECT erx_medication_uuid, pharmacy_name FROM ' . self::TABLE
            . ' WHERE erx_medication_uuid IN (' . implode(',', array_fill(0, count($medicationUuids), '?')) . ')',
            $medicationUuids
        );

        $names = [];
        foreach ($rows as $row) {
            $names[(string)$row['erx_medication_uuid']] = (string)$row['pharmacy_name'];
        }

        return $names;
    }
}
