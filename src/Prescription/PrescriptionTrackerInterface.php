<?php

/**
 * What the inbound poller needs from the sent-prescription record.
 *
 * Narrow so the poller can be tested without a database.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Prescription;

interface PrescriptionTrackerInterface
{
    /**
     * The OpenEMR patient a transmitted medication belongs to, if it was sent
     * from this install.
     */
    public function findPid(string $medicationUuid): ?int;

    /**
     * Record the pharmacy's answer to a cancel request.
     */
    public function applyCancelResponse(string $medicationUuid, string $outcome, string $detail): void;

    public function applyFillStatus(string $medicationUuid, string $fillStatus, string $detail): void;

    public function applyTransmissionStatus(string $medicationUuid, string $status, string $detail): void;
}
