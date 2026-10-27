<?php

/**
 * Reflects LogiCoy prescriptions into OpenEMR's native prescriptions table.
 *
 * That table is what the chart's Prescriptions card and the native Rx screen
 * read, so a script sent through LogiCoy appears there alongside scripts
 * written natively. Writes go through PrescriptionService rather than raw SQL,
 * and every write is guarded by logicoy_erx_message_map so a replayed poll or a
 * retried send updates nothing and creates no duplicate script.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Prescription;

use LogiCoy\ERx\Config\SessionContext;
use OpenEMR\Common\Logging\EventAuditLogger;

class PrescriptionWriter
{
    /** Value of prescriptions.erx_source for a script that came from an eRx network. */
    private const ERX_SOURCE_EXTERNAL = 1;

    private PrescriptionGatewayInterface $gateway;
    private MessageMapInterface $messageMap;
    private ?EventAuditLogger $auditLogger;

    public function __construct(
        ?PrescriptionGatewayInterface $gateway = null,
        ?MessageMapInterface $messageMap = null,
        ?EventAuditLogger $auditLogger = null
    ) {
        $this->gateway = $gateway ?? new OpenEmrPrescriptionGateway();
        $this->messageMap = $messageMap ?? new MessageMap();
        $this->auditLogger = $auditLogger;
    }

    /**
     * The prescription already written for this message, if any.
     */
    public function existingPrescriptionId(string $logicoyMessageId): ?int
    {
        return $this->messageMap->findPrescriptionId($logicoyMessageId);
    }

    /**
     * Write a prescription, or return the id already written for this message.
     *
     * @param string $logicoyMessageId the LogiCoy identifier of the script: the
     *     medication uuid for an RxNew sent from here, the message id for an
     *     inbound notification
     * @param array<string, mixed> $prescription normalized LogiCoy medication:
     *     drugName, ndcNumber, sig, quantity, refills, effectiveDate,
     *     substitutionAllowed (bool; left as it was when absent), providerUserId,
     *     encounter, dateAdded (when the script was sent, for a backfill;
     *     defaults to now)
     * @return array{prescription_id: int, created: bool}
     */
    public function reflect(string $logicoyMessageId, int $patientId, array $prescription): array
    {
        $existingId = $this->messageMap->findPrescriptionId($logicoyMessageId);
        if ($existingId !== null) {
            // Replay. The script already exists; do not write a second one.
            return ['prescription_id' => $existingId, 'created' => false];
        }

        $data = $this->toPrescriptionRow($logicoyMessageId, $patientId, $prescription);

        $prescriptionId = $this->gateway->insert($data);

        $this->messageMap->map($logicoyMessageId, $prescriptionId, $patientId);

        // Writing a prescription is an audited clinical action. The comment
        // carries the message id only — never the drug or the sig.
        $this->getAuditLogger()->newEvent(
            'logicoy-erx-prescription',
            SessionContext::getString('authUser') ?: 'logicoy-erx-worker',
            SessionContext::getString('authProvider') ?: 'Default',
            1,
            'Prescription reflected from LogiCoy message ' . $logicoyMessageId,
            $patientId
        );

        return ['prescription_id' => $prescriptionId, 'created' => true];
    }

    /**
     * Bring the chart row for a message up to date, or write it if there is
     * none yet. Only what the medication carries is changed; the patient and
     * the date it was added stay.
     *
     * @param array<string, mixed> $prescription as for reflect()
     * @return array{prescription_id: int, created: bool}
     */
    public function update(string $logicoyMessageId, int $patientId, array $prescription): array
    {
        $existingId = $this->messageMap->findPrescriptionId($logicoyMessageId);
        if ($existingId === null) {
            return $this->reflect($logicoyMessageId, $patientId, $prescription);
        }

        $row = $this->toPrescriptionRow($logicoyMessageId, $patientId, $prescription);
        $changes = array_intersect_key($row, array_flip(self::UPDATABLE));
        $changes['date_modified'] = date('Y-m-d H:i:s');
        if (!empty($prescription['providerUserId'])) {
            $changes['updated_by'] = (int)$prescription['providerUserId'];
        }

        $this->gateway->update($existingId, $changes);

        $this->getAuditLogger()->newEvent(
            'logicoy-erx-prescription',
            SessionContext::getString('authUser') ?: 'logicoy-erx-worker',
            SessionContext::getString('authProvider') ?: 'Default',
            1,
            'Prescription updated from LogiCoy message ' . $logicoyMessageId,
            $patientId
        );

        return ['prescription_id' => $existingId, 'created' => false];
    }

    /**
     * Take the chart row for a message off the chart, if there is one.
     *
     * @return bool whether a row was there to deactivate
     */
    public function deactivate(string $logicoyMessageId, int $patientId): bool
    {
        $existingId = $this->messageMap->findPrescriptionId($logicoyMessageId);
        if ($existingId === null) {
            return false;
        }

        $this->gateway->deactivate($existingId);

        $this->getAuditLogger()->newEvent(
            'logicoy-erx-prescription',
            SessionContext::getString('authUser') ?: 'logicoy-erx-worker',
            SessionContext::getString('authProvider') ?: 'Default',
            1,
            'Prescription deactivated from LogiCoy message ' . $logicoyMessageId,
            $patientId
        );

        return true;
    }

    /** What a later change to the medication may alter on an existing row. */
    private const UPDATABLE = [
        'drug', 'note', 'drug_dosage_instructions', 'quantity', 'refills', 'start_date',
        'drug_info_erx', 'provider_id', 'encounter', 'substitute',
    ];

    /** prescriptions.substitute codes, as core's Prescription class lists them. */
    private const SUBSTITUTE_YES = 1;
    private const SUBSTITUTE_NO = 2;

    /**
     * Map a LogiCoy medication onto the prescriptions table.
     *
     * Only `drug` and `patient_id` are required by PrescriptionService; the
     * rest are best effort so a sparse backend payload still writes. The
     * columns are the ones the chart's Prescriptions card shows (drug,
     * quantity, refills, date added) plus what the native edit screen and the
     * FHIR MedicationRequest read. PrescriptionService drops any column the
     * installed schema does not have, so naming a newer column is safe on an
     * older OpenEMR.
     *
     * @param array<string, mixed> $prescription
     * @return array<string, mixed>
     */
    private function toPrescriptionRow(string $logicoyMessageId, int $patientId, array $prescription): array
    {
        $now = self::isoDateTime((string)($prescription['dateAdded'] ?? '')) ?? date('Y-m-d H:i:s');
        $today = substr($now, 0, 10);

        $row = [
            'patient_id' => $patientId,
            'drug' => mb_substr(trim((string)($prescription['drugName'] ?? '')), 0, 150),
            'date_added' => $now,
            'date_modified' => $now,
            'datetime' => $now,
            'start_date' => $today,
            // NOT NULL with no default; a strict-mode server refuses an insert
            // that leaves it out.
            'txDate' => $today,
            'active' => 1,
            // The LogiCoy identifier, so the native row can be traced back to
            // the transmission that produced it.
            'prescriptionguid' => mb_substr($logicoyMessageId, 0, 50),
            'erx_source' => self::ERX_SOURCE_EXTERNAL,
            // Both NOT NULL with no default, as above. The native screen
            // writes '' for an unset category too.
            'usage_category_title' => '',
            'request_intent_title' => '',
        ];

        $user = SessionContext::getString('authUser');
        if ($user !== '') {
            $row['user'] = mb_substr($user, 0, 50);
        }
        $site = SessionContext::getString('site_id');
        if ($site !== '') {
            $row['site'] = mb_substr($site, 0, 50);
        }

        // The NDC is not a drug_id: that column is an INT foreign key into the
        // in-house drugs table, and an 11-digit NDC overflows it. It goes
        // where NewCrop kept its drug identifiers.
        $ndc = preg_replace('/\D/', '', (string)($prescription['ndcNumber'] ?? '')) ?? '';
        if ($ndc !== '') {
            $row['drug_info_erx'] = 'NDC ' . $ndc;
        }

        $sig = trim((string)($prescription['sig'] ?? ''));
        if ($sig !== '') {
            // note is what the native edit screen shows; the dosage
            // instructions column is what FHIR reports.
            $row['note'] = $sig;
            $row['drug_dosage_instructions'] = $sig;
        }
        if (isset($prescription['quantity']) && trim((string)$prescription['quantity']) !== '') {
            $row['quantity'] = mb_substr(trim((string)$prescription['quantity']), 0, 31);
        }
        if (isset($prescription['refills']) && $prescription['refills'] !== '') {
            $row['refills'] = max(0, (int)$prescription['refills']);
        }
        if (is_bool($prescription['substitutionAllowed'] ?? null)) {
            $row['substitute'] = $prescription['substitutionAllowed'] ? self::SUBSTITUTE_YES : self::SUBSTITUTE_NO;
        }
        if (!empty($prescription['providerUserId'])) {
            $row['provider_id'] = (int)$prescription['providerUserId'];
            $row['created_by'] = (int)$prescription['providerUserId'];
        }
        if (!empty($prescription['encounter'])) {
            $row['encounter'] = (int)$prescription['encounter'];
        }
        $effective = self::isoDate((string)($prescription['effectiveDate'] ?? ''));
        if ($effective !== null) {
            $row['start_date'] = $effective;
        }

        return $row;
    }

    /**
     * A date the backend gave, as Y-m-d, or null when it is not one. The
     * backend sends dates as 2026-03-16 or with a time appended; anything
     * else is left for start_date's default of today.
     */
    private static function isoDate(string $value): ?string
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($value), $m) !== 1) {
            return null;
        }
        [$y, $mo, $d] = array_map('intval', explode('-', $m[1]));

        return checkdate($mo, $d, $y) ? $m[1] : null;
    }

    /**
     * A recorded Y-m-d H:i:s, or null when the value is not one.
     */
    private static function isoDateTime(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) !== 1) {
            return null;
        }

        return self::isoDate($value) === null ? null : $value;
    }

    /**
     * Resolved lazily so unit tests can construct the writer without a session.
     */
    private function getAuditLogger(): EventAuditLogger
    {
        return $this->auditLogger ??= EventAuditLogger::getInstance();
    }
}
