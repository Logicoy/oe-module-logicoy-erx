<?php

/**
 * What the inbound poller needs from the notification record.
 *
 * Narrow so the poller's replay behaviour can be tested without a database.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Prescription;

interface NotificationStoreInterface
{
    /**
     * Store a notification on first sight and return the stored row.
     *
     * Later sightings of the same notification uuid refresh display fields
     * only. They never change state, so a replayed poll cannot reopen a request
     * the prescriber has already answered.
     *
     * @param array<string, mixed> $notification normalised by NotificationPoller
     * @return array<string, mixed>
     */
    public function record(array $notification): array;

    /**
     * Follow-up lookups for this notification are done; a replay skips it.
     * What they found is recorded on the prescription, not here.
     */
    public function markProcessed(string $notificationUuid): void;

    /**
     * A follow-up lookup failed. It is retried on the next poll until
     * $maxAttempts, then given up on so one bad notification cannot cost a
     * backend call on every poll forever.
     */
    public function markProcessingFailed(string $notificationUuid, string $error, int $maxAttempts): void;

    /**
     * The OpenEMR patient behind a LogiCoy patient uuid, if this install
     * synced them to the given facility's practice.
     */
    public function resolvePatientId(string $erxPatientUuid, int $facilityId): ?int;

    /**
     * Cache what get-details returned for a request, so the screen can show
     * it and a response can be built from what the prescriber reviewed.
     *
     * @param array<string, mixed> $detail
     */
    public function saveRequestDetail(string $notificationUuid, array $detail): void;

    /**
     * Fill in who a request is about once its details are known: the
     * OpenEMR patient when one could be matched, the eRx patient uuid and
     * the name on the request. Never overwrites a value already held, so a
     * patient linked by hand stays linked.
     */
    public function attachPatient(
        string $notificationUuid,
        ?int $pid,
        string $erxPatientUuid,
        string $patientName
    ): void;
}
