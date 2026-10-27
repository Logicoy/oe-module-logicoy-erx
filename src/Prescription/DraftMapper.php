<?php

/**
 * Maps the prescribing form onto the eRx medication shape, and flattens draft
 * responses for the screen.
 *
 * Validation here is structural only — required fields, sane numbers. Clinical
 * checks (interactions, allergies, dosing) are the backend's job.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Prescription;

use LogiCoy\ERx\Client\BackendClient;

class DraftMapper
{
    /**
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    public static function toMedication(array $form): array
    {
        $medication = [
            'ndcNumber' => trim((string)($form['ndcNumber'] ?? '')),
            'drugName' => trim((string)($form['drugName'] ?? '')),
            'effectiveDate' => (string)($form['effectiveDate'] ?? '') ?: date('Y-m-d'),
            'noOfUnits' => (string)($form['quantity'] ?? ''),
            'daysSupply' => (int)($form['daysSupply'] ?? 0),
            'sig' => trim((string)($form['sig'] ?? '')),
            'refillCount' => (int)($form['refills'] ?? 0),
            'rxDrugType' => (string)($form['rxDrugType'] ?? '1'),
            // Always sent: the backend reads a missing value as "allowed",
            // while this screen defaults to dispense as written.
            'isSubstitutionAllowed' => self::substitutionAllowed($form),
        ];

        if (!empty($form['notesToPharmacist'])) {
            $medication['notesToPharmacist'] = trim((string)$form['notesToPharmacist']);
        }
        if (!empty($form['medicationUuid'])) {
            $medication['erxEprescriptionMedicationPKUuid'] = (string)$form['medicationUuid'];
        }

        return $medication;
    }

    /**
     * Whether the prescriber allowed generic substitution. Only an explicit
     * yes counts; anything else, including a missing field, is "do not
     * substitute".
     *
     * @param array<string, mixed> $form
     */
    public static function substitutionAllowed(array $form): bool
    {
        $value = $form['substitutionAllowed'] ?? false;
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * @param array<string, mixed> $form
     * @param bool $allowControlled the prescriber is EPCS and VERIFIED with ID.me,
     *     so a controlled substance may be drafted (MFA is checked on send)
     * @param bool $forUpdate an existing draft is being changed: it must be
     *     named, and the pharmacy it already has may be kept
     * @return array<int, string>
     */
    public static function validate(array $form, bool $allowControlled = false, bool $forUpdate = false): array
    {
        $errors = [];

        if ($forUpdate && trim((string)($form['medicationUuid'] ?? '')) === '') {
            $errors[] = xl('No draft selected to update');
        }

        if (empty($form['drugName'])) {
            $errors[] = xl('Select a drug');
        }

        $ndc = preg_replace('/\D/', '', (string)($form['ndcNumber'] ?? '')) ?? '';
        if ($ndc === '') {
            $errors[] = xl('NDC number is required');
        } elseif (strlen($ndc) < 9 || strlen($ndc) > 11) {
            $errors[] = xl('NDC number must be 9 to 11 digits');
        }

        if (empty($form['sig'])) {
            $errors[] = xl('Directions (sig) are required');
        }
        if (($form['quantity'] ?? '') === '') {
            $errors[] = xl('Quantity is required');
        }
        if ((int)($form['daysSupply'] ?? 0) <= 0) {
            $errors[] = xl('Days supply must be greater than zero');
        }
        if ((int)($form['refills'] ?? 0) < 0) {
            $errors[] = xl('Refills cannot be negative');
        }
        // A new draft needs a pharmacy; an update without one keeps the
        // pharmacy the draft was saved with.
        if (!$forUpdate && empty($form['pharmacyOrgId'])) {
            $errors[] = xl('Select a pharmacy');
        }

        // A controlled substance is accepted only from an EPCS prescriber whose
        // identity ID.me has verified; the MFA step is enforced when the draft
        // is sent. EPCS signing itself lives in the LogiCoy backend.
        if (!$allowControlled && !empty($form['isControlled']) && $form['isControlled'] !== 'false') {
            $errors[] = xl('Controlled substances need an EPCS prescriber verified with ID.me');
        }

        return $errors;
    }

    /**
     * Why draft/add did not store the draft, or null when it did.
     *
     * The backend answers 200 and reports validation per medication, so a
     * rejected draft otherwise reads as "Draft saved." and never appears in the
     * list. Only error fields are read; the body echoes prescription content.
     *
     * @param array<string, mixed> $response
     */
    public static function addFailure(array $response): ?string
    {
        $failure = BackendClient::reportedFailure($response);
        if ($failure !== null) {
            return $failure;
        }

        $list = $response['eprescriptionMedicationList']
            ?? $response['data']['eprescriptionMedicationList']
            ?? [];
        foreach (is_array($list) ? $list : [] as $m) {
            if (is_array($m) && ($m['isValidationError'] ?? false) === true) {
                return BackendClient::reportedFailure($m) ?? 'The LogiCoy backend did not accept this draft';
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<int, array<string, mixed>>
     */
    public static function extract(array $response): array
    {
        // GetDraftPrescriptionsDTO returns the drafts as draftPrescriptions;
        // the medication-list key is what draft/add echoes back.
        $list = $response['draftPrescriptions']
            ?? $response['data']['draftPrescriptions']
            ?? $response['eprescriptionMedicationList']
            ?? $response['data']['eprescriptionMedicationList']
            ?? [];

        if (!is_array($list)) {
            return [];
        }

        $drafts = [];
        foreach ($list as $m) {
            if (!is_array($m)) {
                continue;
            }
            $drafts[] = [
                'medicationUuid' => (string)($m['erxEprescriptionMedicationPKUuid'] ?? ''),
                'drugName' => (string)($m['drugName'] ?? ''),
                'ndcNumber' => (string)($m['ndcNumber'] ?? ''),
                'sig' => (string)($m['sig'] ?? ''),
                'quantity' => (string)($m['noOfUnits'] ?? ''),
                'daysSupply' => (string)($m['daysSupply'] ?? ''),
                'refills' => (string)($m['refillCount'] ?? ''),
                'effectiveDate' => (string)($m['effectiveDate'] ?? ''),
                'notes' => (string)($m['notesToPharmacist'] ?? ''),
                // A draft saved before this field was sent carries null, which
                // the backend treats as allowed; show it as what will happen.
                'substitutionAllowed' => ($m['isSubstitutionAllowed'] ?? true) !== false,
                'status' => (string)($m['erxTxStatus'] ?? $m['prescriptionStatus'] ?? $m['status'] ?? '') ?: 'draft',
            ];
        }

        return $drafts;
    }
}
