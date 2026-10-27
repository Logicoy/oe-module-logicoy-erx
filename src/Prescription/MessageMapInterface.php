<?php

/**
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Prescription;

interface MessageMapInterface
{
    public function findPrescriptionId(string $logicoyMessageId): ?int;

    public function map(string $logicoyMessageId, int $prescriptionId, int $patientId): void;
}
