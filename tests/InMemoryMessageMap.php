<?php

/**
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Prescription\MessageMapInterface;

class InMemoryMessageMap implements MessageMapInterface
{
    /** @var array<string, int> */
    private array $map = [];

    public int $mapCallCount = 0;

    public function findPrescriptionId(string $logicoyMessageId): ?int
    {
        return $this->map[$logicoyMessageId] ?? null;
    }

    public function map(string $logicoyMessageId, int $prescriptionId, int $patientId): void
    {
        $this->mapCallCount++;
        // Mirrors the ON DUPLICATE KEY UPDATE that keeps the first winner.
        $this->map[$logicoyMessageId] ??= $prescriptionId;
    }
}
