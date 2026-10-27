<?php

/**
 * Maps a LogiCoy message id to the OpenEMR prescription it produced.
 *
 * This is the replay guard: cron is unreliable on shared hosting and will
 * redeliver, so the map is consulted before any write.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Prescription;

use OpenEMR\Common\Database\QueryUtils;

class MessageMap implements MessageMapInterface
{
    public const TABLE = 'logicoy_erx_message_map';

    public function findPrescriptionId(string $logicoyMessageId): ?int
    {
        $id = QueryUtils::fetchSingleValue(
            'SELECT prescription_id FROM ' . self::TABLE . ' WHERE logicoy_message_id = ?',
            'prescription_id',
            [$logicoyMessageId]
        );

        return empty($id) ? null : (int)$id;
    }

    public function map(string $logicoyMessageId, int $prescriptionId, int $patientId): void
    {
        QueryUtils::sqlInsert(
            'INSERT INTO ' . self::TABLE . ' SET '
            . 'logicoy_message_id = ?, prescription_id = ?, patient_id = ?, created_at = NOW() '
            // A concurrent worker may have mapped it first; keep the winner.
            . 'ON DUPLICATE KEY UPDATE prescription_id = prescription_id',
            [$logicoyMessageId, $prescriptionId, $patientId]
        );
    }
}
