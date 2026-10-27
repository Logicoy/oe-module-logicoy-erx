<?php

/**
 * The single seam between this module and OpenEMR's prescription storage.
 *
 * Keeping it narrow means the write path can be unit tested without a database,
 * and there is exactly one class to revisit when core changes.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Prescription;

interface PrescriptionGatewayInterface
{
    /**
     * @param array<string, mixed> $data a prescriptions table row
     * @return int the new prescription id
     *
     * @throws PrescriptionWriteException when the write is rejected
     */
    public function insert(array $data): int;

    /**
     * Change columns on an existing row. Only the columns given are touched.
     *
     * @param array<string, mixed> $data prescriptions table columns
     *
     * @throws PrescriptionWriteException when the write is rejected
     */
    public function update(int $prescriptionId, array $data): void;

    /**
     * Take the row off the chart (active = 0). The row stays, as OpenEMR's
     * own delete does, so the trail is kept.
     *
     * @throws PrescriptionWriteException when the write is rejected
     */
    public function deactivate(int $prescriptionId): void;
}
