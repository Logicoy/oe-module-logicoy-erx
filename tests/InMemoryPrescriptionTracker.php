<?php

/**
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Prescription\PrescriptionTrackerInterface;

class InMemoryPrescriptionTracker implements PrescriptionTrackerInterface
{
    /** @var array<string, array<string, mixed>> medication uuid => row */
    public array $rows = [];

    public function add(string $medicationUuid, int $pid): void
    {
        $this->rows[$medicationUuid] = [
            'pid' => $pid,
            'status' => 'sent',
            'transmission_status' => null,
            'fill_status' => null,
            'status_detail' => null,
        ];
    }

    public function findPid(string $medicationUuid): ?int
    {
        return isset($this->rows[$medicationUuid]) ? (int)$this->rows[$medicationUuid]['pid'] : null;
    }

    public function applyCancelResponse(string $medicationUuid, string $outcome, string $detail): void
    {
        if (isset($this->rows[$medicationUuid])) {
            $this->rows[$medicationUuid]['status'] = $outcome;
            $this->rows[$medicationUuid]['status_detail'] = $detail;
        }
    }

    public function applyFillStatus(string $medicationUuid, string $fillStatus, string $detail): void
    {
        if (isset($this->rows[$medicationUuid])) {
            $this->rows[$medicationUuid]['fill_status'] = $fillStatus;
        }
    }

    public function applyTransmissionStatus(string $medicationUuid, string $status, string $detail): void
    {
        if (isset($this->rows[$medicationUuid])) {
            $this->rows[$medicationUuid]['transmission_status'] = $status;
        }
    }
}
