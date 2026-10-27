<?php

/**
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Prescription\DraftMapper;
use PHPUnit\Framework\TestCase;

class DraftMapperTest extends TestCase
{
    public function testExtractReadsDraftPrescriptionsFromTheListResponse(): void
    {
        // Shape per GetDraftPrescriptionsDTO. Synthetic data.
        $drafts = DraftMapper::extract([
            'transactionStatus' => ['statusCode' => '200'],
            'isValidationError' => false,
            'draftPrescriptions' => [[
                'erxEprescriptionMedicationPKUuid' => 'med-uuid-1',
                'drugName' => 'Synthetic Drug 10 mg Tablet',
                'ndcNumber' => '00000000000',
                'sig' => 'take 1',
                'noOfUnits' => '10',
                'daysSupply' => 10,
                'refillCount' => 0,
                'effectiveDate' => '2026-10-12',
                'erxTxStatus' => 'DRAFT',
            ]],
        ]);

        $this->assertCount(1, $drafts);
        $this->assertSame('med-uuid-1', $drafts[0]['medicationUuid']);
        $this->assertSame('10', $drafts[0]['quantity']);
        $this->assertSame('10', $drafts[0]['daysSupply']);
        $this->assertSame('DRAFT', $drafts[0]['status']);
    }

    public function testExtractKeepsTheNotesToThePharmacist(): void
    {
        $drafts = DraftMapper::extract(['draftPrescriptions' => [[
            'erxEprescriptionMedicationPKUuid' => 'med-uuid-1',
            'drugName' => 'Synthetic Drug',
            'notesToPharmacist' => 'Dispense as written',
        ]]]);

        $this->assertSame('Dispense as written', $drafts[0]['notes']);
    }

    public function testExtractStillReadsTheMedicationListKey(): void
    {
        $drafts = DraftMapper::extract([
            'data' => ['eprescriptionMedicationList' => [['erxEprescriptionMedicationPKUuid' => 'med-uuid-2']]],
        ]);

        $this->assertSame('med-uuid-2', $drafts[0]['medicationUuid']);
        $this->assertSame('draft', $drafts[0]['status']);
    }

    public function testExtractReturnsNothingForAnEmptyList(): void
    {
        $this->assertSame([], DraftMapper::extract(['draftPrescriptions' => []]));
        $this->assertSame([], DraftMapper::extract([]));
    }

    public function testAddFailureIsNullWhenTheDraftWasStored(): void
    {
        $this->assertNull(DraftMapper::addFailure([
            'isValidationError' => false,
            'eprescriptionMedicationList' => [[
                'erxEprescriptionMedicationPKUuid' => 'med-uuid-3',
                'isValidationError' => false,
            ]],
        ]));
    }

    public function testAddFailureReportsATopLevelValidationError(): void
    {
        $failure = DraftMapper::addFailure([
            'isValidationError' => true,
            'validationErrorInfo' => [['errorMessage' => 'erxPatientPKUuid is invalid']],
        ]);

        $this->assertSame('erxPatientPKUuid is invalid', $failure);
    }

    public function testAddFailureReportsAPerMedicationValidationError(): void
    {
        $failure = DraftMapper::addFailure([
            'isValidationError' => false,
            'eprescriptionMedicationList' => [[
                'isValidationError' => true,
                'validationErrorInfo' => [['errorMessage' => 'sig is required']],
            ]],
        ]);

        $this->assertSame('sig is required', $failure);
    }

    /** @return array<string, string> a complete form, as the screen posts it */
    private function completeForm(): array
    {
        return [
            'drugName' => 'Synthetic Drug 10 mg Tablet',
            'ndcNumber' => '00000000000',
            'sig' => 'take 1',
            'quantity' => '10',
            'daysSupply' => '10',
            'refills' => '0',
            'pharmacyOrgId' => '12345',
            'isControlled' => 'false',
        ];
    }

    /*
     * Only the passing paths of validate() can run here: an error message
     * goes through xl(), which needs OpenEMR's database for translations.
     */
    public function testValidateForUpdateAcceptsADraftWithoutAPharmacy(): void
    {
        $form = $this->completeForm() + ['medicationUuid' => 'med-uuid-1'];
        unset($form['pharmacyOrgId']);

        // An update keeps the pharmacy the draft already has.
        $this->assertSame([], DraftMapper::validate($form, false, true));
    }

    public function testValidateAcceptsAControlledDrugWhenAllowed(): void
    {
        $form = $this->completeForm();
        $form['isControlled'] = 'true';

        $this->assertSame([], DraftMapper::validate($form, true));
        $this->assertSame([], DraftMapper::validate($form + ['medicationUuid' => 'med-uuid-1'], true, true));
    }

    public function testToMedicationCarriesTheDraftUuidWhenUpdating(): void
    {
        $medication = DraftMapper::toMedication($this->completeForm() + ['medicationUuid' => 'med-uuid-1']);
        $this->assertSame('med-uuid-1', $medication['erxEprescriptionMedicationPKUuid']);

        $fresh = DraftMapper::toMedication($this->completeForm());
        $this->assertArrayNotHasKey('erxEprescriptionMedicationPKUuid', $fresh);
    }

    public function testToMedicationDefaultsToDispenseAsWritten(): void
    {
        // The backend reads a missing value as "allowed", so false is always sent.
        $this->assertFalse(DraftMapper::toMedication($this->completeForm())['isSubstitutionAllowed']);
        foreach (['false', '0', '', 'nonsense', false] as $no) {
            $medication = DraftMapper::toMedication($this->completeForm() + ['substitutionAllowed' => $no]);
            $this->assertFalse($medication['isSubstitutionAllowed']);
        }
    }

    public function testToMedicationSendsAnExplicitlyAllowedSubstitution(): void
    {
        foreach (['true', '1', 'on', true] as $yes) {
            $medication = DraftMapper::toMedication($this->completeForm() + ['substitutionAllowed' => $yes]);
            $this->assertTrue($medication['isSubstitutionAllowed']);
        }
    }

    public function testExtractReadsTheSubstitutionChoice(): void
    {
        $drafts = DraftMapper::extract(['draftPrescriptions' => [
            ['erxEprescriptionMedicationPKUuid' => 'a', 'isSubstitutionAllowed' => false],
            ['erxEprescriptionMedicationPKUuid' => 'b', 'isSubstitutionAllowed' => true],
            // Saved before the field was sent: the backend treats it as allowed.
            ['erxEprescriptionMedicationPKUuid' => 'c', 'isSubstitutionAllowed' => null],
        ]]);

        $this->assertFalse($drafts[0]['substitutionAllowed']);
        $this->assertTrue($drafts[1]['substitutionAllowed']);
        $this->assertTrue($drafts[2]['substitutionAllowed']);
    }
}
