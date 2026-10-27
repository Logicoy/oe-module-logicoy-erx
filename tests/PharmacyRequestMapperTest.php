<?php

/**
 * Renewal and change responses. All data here is synthetic.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use InvalidArgumentException;
use LogiCoy\ERx\Prescription\PharmacyRequestMapper as Mapper;
use PHPUnit\Framework\TestCase;

class PharmacyRequestMapperTest extends TestCase
{
    private const CONTEXT = [
        'transactionUuid' => 'txn-test-0001',
        'erxPracticePKUuid' => 'practice-test-0001',
        'erxPracticeServiceLocPKUuid' => 'location-test-0001',
        'erxClinicianUserPKUuid' => 'clinician-test-0001',
    ];

    protected function setUp(): void
    {
        // xl() would otherwise look the string up in the translation tables.
        $GLOBALS['temp_skip_translations'] = true;
    }

    /** @return array<string, mixed> */
    private function renewalResponse(
        array $accepted = ['APPROVED', 'APPROVED_WITH_CHANGES', 'DENIED', 'REPLACE'],
        bool $withDispensed = false
    ): array {
        $dispensed = $withDispensed ? ['medicationDispensed' => [
            'drugDescription' => 'TEST-DRUG 10 MG tablet',
            'drugCoded' => ['productCode' => ['code' => '00000000000', 'qualifier' => 'ND']],
            'quantity' => ['value' => '30', 'codeListQualifier' => '38'],
            'sig' => ['sigText' => 'Synthetic directions for test use only'],
            'daysSupply' => '30',
            'pharmacyRequestedRefills' => 10,
            'lastFillDate' => ['date' => '2026-10-26'],
            'note' => 'Synthetic note',
            'pharmacy' => ['businessName' => 'not part of a response'],
        ]] : [];

        return ['rxRenewalRequestFromPharmacy' => $dispensed + [
            'patient' => ['firstName' => 'Test', 'lastName' => 'Patient', 'dateOfBirth' => '1980-01-01'],
            'pharmacy' => [
                'businessName' => 'Test Pharmacy',
                'identification' => ['ncpdpid' => '0000000'],
                'communicationNumbers' => ['primaryTelephone' => ['number' => '5550000000']],
            ],
            'medicationPrescribed' => [
                'drugDescription' => 'TEST-DRUG 10 MG tablet',
                'drugCoded' => ['productCode' => ['code' => '00000000000', 'qualifier' => 'ND']],
                'quantity' => ['value' => '30', 'codeListQualifier' => '38'],
                'sig' => ['sigText' => 'Synthetic directions for test use only'],
                'daysSupply' => '30',
                'numberOfRefills' => 0,
            ],
            'acceptedResponseCodes' => $accepted,
        ]];
    }

    /** @return array<string, mixed> */
    private function changeResponse(
        array $accepted = ['APPROVED', 'DENIED', 'APPROVED_WITH_CHANGES', 'VALIDATED']
    ): array {
        return ['rxChangeRequestFromPharmacy' => [
            'rxChangeMsgReqCodeTypeRef' => ['name' => 'G', 'description' => 'Generic Substitution'],
            'medicationRequested' => [
                [
                    'requestedMedicaitonId' => 7,
                    'drugDescription' => 'TEST-GENERIC 10 MG tablet',
                    'drugCoded' => ['productCode' => ['code' => '11111111111', 'qualifier' => 'ND']],
                    'quantity' => ['value' => '30', 'codeListQualifier' => '38'],
                    'sig' => ['sigText' => 'Synthetic directions for test use only'],
                    'pharmacy' => [['businessName' => 'not part of a response']],
                ],
            ],
            'acceptedResponseCodes' => $accepted,
        ]];
    }

    public function testNotificationTypesAreRecognised(): void
    {
        $this->assertSame(Mapper::TYPE_RENEWAL, Mapper::typeForNotification('RX_RENEWAL_REQUEST'));
        $this->assertSame(Mapper::TYPE_CHANGE, Mapper::typeForNotification('rx_change_request'));
        $this->assertNull(Mapper::typeForNotification('RX_FILL'));
    }

    public function testRenewalDetailsAreFlattenedForTheScreen(): void
    {
        $details = Mapper::renewalDetails($this->renewalResponse());

        $this->assertSame('Test Pharmacy', $details['pharmacy']['name']);
        $this->assertSame('0000000', $details['pharmacy']['ncpdpId']);
        $this->assertSame('TEST-DRUG 10 MG tablet', $details['prescribed']['description']);
        $this->assertSame('00000000000', $details['prescribed']['ndc']);
        $this->assertSame('30', $details['prescribed']['quantity']);
        $this->assertNull($details['dispensed']);
        // Only what this module can send, and only what the pharmacy accepts.
        $this->assertSame(['APPROVED', 'APPROVED_WITH_CHANGES', 'REPLACE', 'DENIED'], $details['decisions']);

        $details = Mapper::renewalDetails($this->renewalResponse(['APPROVED', 'DENIED'], true));
        $this->assertSame(['APPROVED', 'DENIED'], $details['decisions']);
        $this->assertSame('10', $details['dispensed']['pharmacyRequestedRefills']);
    }

    public function testOnlyAcceptedDecisionsAreOffered(): void
    {
        $details = Mapper::renewalDetails($this->renewalResponse(['DENIED']));
        $this->assertSame(['DENIED'], $details['decisions']);

        $replace = Mapper::renewalDetails($this->renewalResponse(['REPLACE']));
        $this->assertSame(['REPLACE'], $replace['decisions']);

        $none = Mapper::renewalDetails($this->renewalResponse(['SOMETHING_NEW']));
        $this->assertSame([], $none['decisions'], 'nothing this module can send');
    }

    public function testRenewalApprovalCarriesNoMedication(): void
    {
        $cache = Mapper::detailCache(Mapper::TYPE_RENEWAL, $this->renewalResponse());
        $input = ['decision' => 'approved', 'notes' => ''];

        $this->assertSame([], Mapper::validate(Mapper::TYPE_RENEWAL, $input, $cache));

        $payload = Mapper::renewalResponse(self::CONTEXT, 'n-renew-1', $input);

        $this->assertSame('n-renew-1', $payload['erxNotificationPkUuid']);
        $this->assertSame('clinician-test-0001', $payload['erxClinicianUserPKUuid']);
        $this->assertSame(['rxRenewalResponseCodeTypeRef' => 'APPROVED'], $payload['rxRenewalResponseMedication']);
    }

    public function testApprovedRenewalIssuesTheReviewedMedication(): void
    {
        $cache = Mapper::detailCache(Mapper::TYPE_RENEWAL, $this->renewalResponse());
        $payload = Mapper::renewalResponse(self::CONTEXT, 'n-renew-1', ['decision' => 'APPROVED']);

        $script = Mapper::approvedMedication(Mapper::TYPE_RENEWAL, $payload, $cache);

        $this->assertSame([
            'drugName' => 'TEST-DRUG 10 MG tablet',
            'ndcNumber' => '00000000000',
            'sig' => 'Synthetic directions for test use only',
            'quantity' => '30',
            'refills' => '0',
        ], $script);

        $denied = Mapper::renewalResponse(self::CONTEXT, 'n-renew-1', ['decision' => 'DENIED', 'deniedReason' => 'AM']);
        $this->assertNull(Mapper::approvedMedication(Mapper::TYPE_RENEWAL, $denied, $cache));
        // A cache from before the review, or of the other type, issues nothing.
        $this->assertNull(Mapper::approvedMedication(Mapper::TYPE_RENEWAL, $payload, []));
        $this->assertNull(Mapper::approvedMedication(
            Mapper::TYPE_RENEWAL,
            $payload,
            Mapper::detailCache(Mapper::TYPE_CHANGE, $this->changeResponse())
        ));
    }

    public function testApprovedRenewalGrantsTheRefillsThePharmacyAskedForOnTheDispensedMedication(): void
    {
        $cache = Mapper::detailCache(Mapper::TYPE_RENEWAL, $this->renewalResponse(['APPROVED', 'DENIED'], true));
        $payload = Mapper::renewalResponse(self::CONTEXT, 'n-renew-1', ['decision' => 'APPROVED'], $cache);

        // The requested count rides on medicationDispensed, not on the
        // prescribed copy the chart entry is built from.
        $this->assertSame('10', Mapper::approvedMedication(Mapper::TYPE_RENEWAL, $payload, $cache)['refills']);
    }

    public function testRenewalApprovedWithChangesSendsTheDispensedMedicationWithThePrescribersValues(): void
    {
        $cache = Mapper::detailCache(Mapper::TYPE_RENEWAL, $this->renewalResponse(
            ['APPROVED', 'APPROVED_WITH_CHANGES', 'DENIED'],
            true
        ));
        $input = [
            'decision' => 'APPROVED_WITH_CHANGES',
            'quantity' => '60',
            'daysSupply' => '30',
            'sig' => 'Synthetic changed directions',
            'refills' => '3',
            'notes' => 'Synthetic note to the pharmacy',
        ];
        $this->assertSame([], Mapper::validate(Mapper::TYPE_RENEWAL, $input, $cache));

        $payload = Mapper::renewalResponse(self::CONTEXT, 'n-renew-1', $input, $cache);
        $body = $payload['rxRenewalResponseMedication'];
        $this->assertSame('APPROVED_WITH_CHANGES', $body['rxRenewalResponseCodeTypeRef']);
        $this->assertSame('Synthetic note to the pharmacy', $body['notes']);
        $this->assertArrayNotHasKey('rxRenewalDeniedCodeTypeRef', $body);

        // The same shape as a change response: the pharmacy's drug under
        // ndc, its response fields echoed, the prescriber's values over them.
        $sent = $body['medicationResponse'];
        $this->assertSame('00000000000', $sent['ndc']['productCode']['code']);
        $this->assertArrayNotHasKey('drugCoded', $sent);
        $this->assertArrayNotHasKey('pharmacy', $sent, 'context, not part of the answer');
        $this->assertArrayNotHasKey('requestedMedicaitonId', $sent, 'a renewal names no requested medication');
        $this->assertSame('60', $sent['quantity']['value']);
        $this->assertSame('38', $sent['quantity']['codeListQualifier']);
        $this->assertSame(30, $sent['daysSupply']);
        $this->assertSame('Synthetic changed directions', $sent['sig']['sigText']);
        $this->assertSame('3', $sent['numberOfRefills']);
        $this->assertSame(10, $sent['pharmacyRequestedRefills']);
        $this->assertSame(['date' => '2026-10-26'], $sent['lastFillDate']);

        // The chart gets what was sent, not the count the pharmacy asked for.
        $script = Mapper::approvedMedication(Mapper::TYPE_RENEWAL, $payload, $cache);
        $this->assertSame([
            'drugName' => 'TEST-DRUG 10 MG tablet',
            'ndcNumber' => '00000000000',
            'sig' => 'Synthetic changed directions',
            'quantity' => '60',
            'refills' => '3',
        ], $script);

        // A different drug from the backend's search replaces the dispensed one.
        $other = ['drugName' => 'TEST-OTHER 5 MG tablet', 'ndcNumber' => '22222-222-22'] + $input;
        $this->assertSame([], Mapper::validate(Mapper::TYPE_RENEWAL, $other, $cache));
        $payload = Mapper::renewalResponse(self::CONTEXT, 'n-renew-1', $other, $cache);
        $sent = $payload['rxRenewalResponseMedication']['medicationResponse'];
        $this->assertSame('2222222222', $sent['ndc']['productCode']['code']);
        $this->assertSame('TEST-OTHER 5 MG tablet', $sent['drugDescription']);
        $script = Mapper::approvedMedication(Mapper::TYPE_RENEWAL, $payload, $cache);
        $this->assertSame('TEST-OTHER 5 MG tablet', $script['drugName']);
        $this->assertSame('2222222222', $script['ndcNumber']);
    }

    public function testReplaceIsApproveWithChangesUnderAnotherCode(): void
    {
        $input = [
            'quantity' => '60',
            'daysSupply' => '30',
            'sig' => 'Synthetic changed directions',
            'refills' => '3',
        ];

        $cache = Mapper::detailCache(Mapper::TYPE_RENEWAL, $this->renewalResponse(
            ['APPROVED', 'APPROVED_WITH_CHANGES', 'DENIED', 'REPLACE'],
            true
        ));
        $this->assertSame([], Mapper::validate(Mapper::TYPE_RENEWAL, ['decision' => 'REPLACE'] + $input, $cache));
        $replaced = Mapper::renewalResponse(self::CONTEXT, 'n-renew-1', ['decision' => 'REPLACE'] + $input, $cache);
        $changed = Mapper::renewalResponse(
            self::CONTEXT,
            'n-renew-1',
            ['decision' => 'APPROVED_WITH_CHANGES'] + $input,
            $cache
        );
        $this->assertSame('REPLACE', $replaced['rxRenewalResponseMedication']['rxRenewalResponseCodeTypeRef']);
        $this->assertSame(
            $changed['rxRenewalResponseMedication']['medicationResponse'],
            $replaced['rxRenewalResponseMedication']['medicationResponse']
        );
        $this->assertSame(
            Mapper::approvedMedication(Mapper::TYPE_RENEWAL, $changed, $cache),
            Mapper::approvedMedication(Mapper::TYPE_RENEWAL, $replaced, $cache)
        );

        $response = $this->changeResponse(['REPLACE', 'DENIED']);
        $cache = Mapper::detailCache(Mapper::TYPE_CHANGE, $response);
        $this->assertSame(['REPLACE', 'DENIED'], Mapper::changeDetails($response)['decisions']);
        $input = ['decision' => 'REPLACE', 'requestedMedicationId' => '7'] + $input;
        $this->assertSame([], Mapper::validate(Mapper::TYPE_CHANGE, $input, $cache));
        $payload = Mapper::changeResponse(self::CONTEXT, 'n-change-1', $input, $cache);
        $this->assertSame('REPLACE', $payload['rxChangeResponseMedication']['rxChangeResponseCodeTypeRef']);
        $this->assertSame('60', $payload['rxChangeResponseMedication']['medicationResponse']['quantity']['value']);
        $this->assertSame('3', Mapper::approvedMedication(Mapper::TYPE_CHANGE, $payload, $cache)['refills']);
    }

    public function testRenewalApprovedWithChangesStartsFromThePrescribedMedicationWhenNoneWasDispensed(): void
    {
        $cache = Mapper::detailCache(Mapper::TYPE_RENEWAL, $this->renewalResponse());
        $input = [
            'decision' => 'APPROVED_WITH_CHANGES',
            'quantity' => '30',
            'daysSupply' => '',
            'sig' => 'Synthetic directions for test use only',
            'refills' => '1',
        ];
        $this->assertSame([], Mapper::validate(Mapper::TYPE_RENEWAL, $input, $cache));

        $sent = Mapper::renewalResponse(self::CONTEXT, 'n-renew-1', $input, $cache)
            ['rxRenewalResponseMedication']['medicationResponse'];
        $this->assertSame('00000000000', $sent['ndc']['productCode']['code']);
        $this->assertSame('1', $sent['numberOfRefills']);
        $this->assertArrayNotHasKey('daysSupply', $sent);
    }

    public function testRenewalApprovedWithChangesIsCheckedLikeANewScriptAndNeedsAMedication(): void
    {
        $cache = Mapper::detailCache(Mapper::TYPE_RENEWAL, $this->renewalResponse());
        $good = [
            'decision' => 'APPROVED_WITH_CHANGES',
            'quantity' => '20',
            'daysSupply' => '',
            'sig' => 'Synthetic directions',
            'refills' => '0',
        ];
        $this->assertSame([], Mapper::validate(Mapper::TYPE_RENEWAL, $good, $cache));
        foreach ([['quantity' => ''], ['sig' => ' '], ['refills' => '100'], ['ndcNumber' => '123']] as $bad) {
            $this->assertNotEmpty(Mapper::validate(Mapper::TYPE_RENEWAL, $bad + $good, $cache), json_encode($bad));
        }

        // The pharmacy does not accept it: refused before anything is queued.
        $narrow = Mapper::detailCache(Mapper::TYPE_RENEWAL, $this->renewalResponse(['APPROVED', 'DENIED']));
        $this->assertNotEmpty(Mapper::validate(Mapper::TYPE_RENEWAL, $good, $narrow));

        // A reviewed request with no medication at all cannot be changed.
        $empty = Mapper::detailCache(Mapper::TYPE_RENEWAL, ['rxRenewalRequestFromPharmacy' => [
            'acceptedResponseCodes' => ['APPROVED_WITH_CHANGES'],
        ]]);
        $this->assertNotEmpty(Mapper::validate(Mapper::TYPE_RENEWAL, $good, $empty));
        $this->expectException(InvalidArgumentException::class);
        Mapper::renewalResponse(self::CONTEXT, 'n-renew-1', $good, $empty);
    }

    public function testApprovedChangeIssuesTheRequestedMedicationAndValidatedIssuesNothing(): void
    {
        $cache = Mapper::detailCache(Mapper::TYPE_CHANGE, $this->changeResponse());
        $input = ['requestedMedicationId' => '7'];

        $approved = Mapper::changeResponse(self::CONTEXT, 'n-change-1', ['decision' => 'APPROVED'] + $input, $cache);
        $script = Mapper::approvedMedication(Mapper::TYPE_CHANGE, $approved, $cache);
        $this->assertSame('TEST-GENERIC 10 MG tablet', $script['drugName']);
        $this->assertSame('11111111111', $script['ndcNumber']);
        $this->assertSame('30', $script['quantity']);

        $validated = Mapper::changeResponse(self::CONTEXT, 'n-change-1', ['decision' => 'VALIDATED'] + $input, $cache);
        $this->assertNull(Mapper::approvedMedication(Mapper::TYPE_CHANGE, $validated, $cache));
    }

    public function testApprovedWithChangesIsOfferedWhenThePharmacyAcceptsIt(): void
    {
        $response = $this->changeResponse(['APPROVED_WITH_CHANGES', 'DENIED']);
        $cache = Mapper::detailCache(Mapper::TYPE_CHANGE, $response);
        $this->assertSame(['APPROVED_WITH_CHANGES', 'DENIED'], Mapper::changeDetails($response)['decisions']);

        // Sent with the pharmacy's own values, it is the requested medication
        // under the code the pharmacy asked for.
        $input = [
            'decision' => 'APPROVED_WITH_CHANGES',
            'requestedMedicationId' => '7',
            'quantity' => '30',
            'daysSupply' => '',
            'sig' => 'Synthetic directions for test use only',
            'refills' => '0',
        ];
        $this->assertSame([], Mapper::validate(Mapper::TYPE_CHANGE, $input, $cache));

        $payload = Mapper::changeResponse(self::CONTEXT, 'n-change-1', $input, $cache);
        $body = $payload['rxChangeResponseMedication'];
        $this->assertSame('APPROVED_WITH_CHANGES', $body['rxChangeResponseCodeTypeRef']);
        $this->assertSame('30', $body['medicationResponse']['quantity']['value']);
        $this->assertSame(7, $body['medicationResponse']['requestedMedicaitonId']);

        $script = Mapper::approvedMedication(Mapper::TYPE_CHANGE, $payload, $cache);
        $this->assertSame('TEST-GENERIC 10 MG tablet', $script['drugName']);
    }

    public function testApprovedWithChangesCarriesThePrescribersValuesOverThePharmacysCodes(): void
    {
        $cache = Mapper::detailCache(Mapper::TYPE_CHANGE, $this->changeResponse());
        $input = [
            'decision' => 'APPROVED_WITH_CHANGES',
            'requestedMedicationId' => '7',
            'quantity' => '20',
            'daysSupply' => '10',
            'sig' => 'Synthetic changed directions',
            'refills' => '2',
        ];
        $this->assertSame([], Mapper::validate(Mapper::TYPE_CHANGE, $input, $cache));

        $sent = Mapper::changeResponse(self::CONTEXT, 'n-change-1', $input, $cache)
            ['rxChangeResponseMedication']['medicationResponse'];
        $this->assertSame('20', $sent['quantity']['value']);
        // The pharmacy's qualifier stays with the new value.
        $this->assertSame('38', $sent['quantity']['codeListQualifier']);
        $this->assertSame(10, $sent['daysSupply']);
        $this->assertSame('Synthetic changed directions', $sent['sig']['sigText']);
        $this->assertSame('2', $sent['numberOfRefills']);
        $this->assertSame('11111111111', $sent['ndc']['productCode']['code']);

        // The chart gets what was sent, not what the pharmacy asked for.
        $payload = Mapper::changeResponse(self::CONTEXT, 'n-change-1', $input, $cache);
        $script = Mapper::approvedMedication(Mapper::TYPE_CHANGE, $payload, $cache);
        $this->assertSame('20', $script['quantity']);
        $this->assertSame('2', $script['refills']);
        $this->assertSame('Synthetic changed directions', $script['sig']);
        $this->assertSame('11111111111', $script['ndcNumber']);

        // A plain approval ignores the fields and sends the request as is.
        $plain = Mapper::changeResponse(self::CONTEXT, 'n-change-1', ['decision' => 'APPROVED'] + $input, $cache)
            ['rxChangeResponseMedication']['medicationResponse'];
        $this->assertSame('30', $plain['quantity']['value']);
    }

    public function testApprovedWithChangesMayNameADifferentDrugFromTheBackendSearch(): void
    {
        $cache = Mapper::detailCache(Mapper::TYPE_CHANGE, $this->changeResponse());
        $input = [
            'decision' => 'APPROVED_WITH_CHANGES',
            'requestedMedicationId' => '7',
            'drugName' => 'TEST-OTHER 5 MG tablet',
            'ndcNumber' => '22222-222-22',
            'quantity' => '30',
            'daysSupply' => '',
            'sig' => 'Synthetic directions for test use only',
            'refills' => '0',
        ];
        $this->assertSame([], Mapper::validate(Mapper::TYPE_CHANGE, $input, $cache));

        $payload = Mapper::changeResponse(self::CONTEXT, 'n-change-1', $input, $cache);
        $sent = $payload['rxChangeResponseMedication']['medicationResponse'];
        $this->assertSame(['productCode' => ['code' => '2222222222', 'qualifier' => 'ND']], $sent['ndc']);
        $this->assertSame('TEST-OTHER 5 MG tablet', $sent['drugDescription']);
        // Still answers the pharmacy's requested medication.
        $this->assertSame(7, $sent['requestedMedicaitonId']);

        $script = Mapper::approvedMedication(Mapper::TYPE_CHANGE, $payload, $cache);
        $this->assertSame('TEST-OTHER 5 MG tablet', $script['drugName']);
        $this->assertSame('2222222222', $script['ndcNumber']);

        // No NDC: the pharmacy's drug stands and no description is sent.
        unset($input['ndcNumber'], $input['drugName']);
        $sent = Mapper::changeResponse(self::CONTEXT, 'n-change-1', $input, $cache)
            ['rxChangeResponseMedication']['medicationResponse'];
        $this->assertSame('11111111111', $sent['ndc']['productCode']['code']);
        $this->assertArrayNotHasKey('drugDescription', $sent);
    }

    public function testChangesAreCheckedLikeANewScript(): void
    {
        $cache = Mapper::detailCache(Mapper::TYPE_CHANGE, $this->changeResponse());
        $good = [
            'decision' => 'APPROVED_WITH_CHANGES',
            'requestedMedicationId' => '7',
            'quantity' => '20',
            'daysSupply' => '',
            'sig' => 'Synthetic directions',
            'refills' => '0',
        ];
        $this->assertSame([], Mapper::validate(Mapper::TYPE_CHANGE, $good, $cache));

        foreach (
            [
                ['quantity' => ''],
                ['quantity' => 'abc'],
                ['quantity' => '0'],
                ['daysSupply' => '1.5'],
                ['sig' => ' '],
                ['refills' => ''],
                ['refills' => '-1'],
                ['refills' => '100'],
                ['ndcNumber' => '123'],
                ['ndcNumber' => '22222222222', 'drugName' => ''],
            ] as $bad
        ) {
            $this->assertNotEmpty(Mapper::validate(Mapper::TYPE_CHANGE, $bad + $good, $cache), json_encode($bad));
        }
    }

    public function testRenewalDenialNeedsAValidReason(): void
    {
        $cache = Mapper::detailCache(Mapper::TYPE_RENEWAL, $this->renewalResponse());

        $this->assertNotEmpty(Mapper::validate(Mapper::TYPE_RENEWAL, ['decision' => 'DENIED'], $cache));
        $this->assertNotEmpty(
            Mapper::validate(Mapper::TYPE_RENEWAL, ['decision' => 'DENIED', 'deniedReason' => 'ZZ'], $cache)
        );

        $input = ['decision' => 'DENIED', 'deniedReason' => 'am', 'notes' => 'Needs a visit'];
        $this->assertSame([], Mapper::validate(Mapper::TYPE_RENEWAL, $input, $cache));

        $medication = Mapper::renewalResponse(self::CONTEXT, 'n-renew-1', $input)['rxRenewalResponseMedication'];
        $this->assertSame('DENIED', $medication['rxRenewalResponseCodeTypeRef']);
        $this->assertSame('AM', $medication['rxRenewalDeniedCodeTypeRef']);
        $this->assertSame('Needs a visit', $medication['notes']);
    }

    public function testDecisionsTheModuleCannotBuildAreRefused(): void
    {
        $cache = Mapper::detailCache(Mapper::TYPE_RENEWAL, $this->renewalResponse());

        // Changed and replaced medications without their fields, and no
        // decision at all.
        foreach (['APPROVED_WITH_CHANGES', 'REPLACE', 'VALIDATED', ''] as $decision) {
            $this->assertNotEmpty(
                Mapper::validate(Mapper::TYPE_RENEWAL, ['decision' => $decision], $cache),
                $decision . ' must be refused'
            );
        }
    }

    public function testADecisionThePharmacyDoesNotAcceptIsRefused(): void
    {
        $cache = Mapper::detailCache(Mapper::TYPE_RENEWAL, $this->renewalResponse(['DENIED']));

        $this->assertNotEmpty(Mapper::validate(Mapper::TYPE_RENEWAL, ['decision' => 'APPROVED'], $cache));
    }

    public function testRespondingWithoutReviewingIsRefused(): void
    {
        $this->assertNotEmpty(Mapper::validate(Mapper::TYPE_RENEWAL, ['decision' => 'APPROVED'], []));

        // A cache from the other request type is not a review of this one.
        $changeCache = Mapper::detailCache(Mapper::TYPE_CHANGE, $this->changeResponse());
        $this->assertNotEmpty(Mapper::validate(Mapper::TYPE_RENEWAL, ['decision' => 'APPROVED'], $changeCache));
    }

    public function testChangeDetailsListRequestedMedicationsWithTheirIds(): void
    {
        $details = Mapper::changeDetails($this->changeResponse());

        $this->assertSame('Generic Substitution', $details['requestDescription']);
        $this->assertSame(7, $details['requested'][0]['requestedMedicationId']);
        $this->assertSame('11111111111', $details['requested'][0]['ndc']);
        $this->assertSame(['APPROVED', 'APPROVED_WITH_CHANGES', 'VALIDATED', 'DENIED'], $details['decisions']);
    }

    public function testChangeApprovalEchoesTheRequestedMedication(): void
    {
        $cache = Mapper::detailCache(Mapper::TYPE_CHANGE, $this->changeResponse());
        $input = ['decision' => 'APPROVED', 'requestedMedicationId' => '7'];

        $this->assertSame([], Mapper::validate(Mapper::TYPE_CHANGE, $input, $cache));

        $payload = Mapper::changeResponse(self::CONTEXT, 'n-change-1', $input, $cache);
        $medication = $payload['rxChangeResponseMedication']['medicationResponse'];

        $this->assertSame('APPROVED', $payload['rxChangeResponseMedication']['rxChangeResponseCodeTypeRef']);
        $this->assertSame(7, $medication['requestedMedicaitonId'], 'the backend spells it this way');
        // drugCoded is renamed ndc, and passed through exactly as received.
        $this->assertSame(['productCode' => ['code' => '11111111111', 'qualifier' => 'ND']], $medication['ndc']);
        $this->assertSame(['value' => '30', 'codeListQualifier' => '38'], $medication['quantity']);
        $this->assertArrayNotHasKey('pharmacy', $medication, 'context fields are not part of the answer');
        $this->assertArrayNotHasKey('drugCoded', $medication);
    }

    public function testChangeResponseMustNameARequestedMedication(): void
    {
        $cache = Mapper::detailCache(Mapper::TYPE_CHANGE, $this->changeResponse());

        $this->assertNotEmpty(Mapper::validate(Mapper::TYPE_CHANGE, ['decision' => 'APPROVED'], $cache));
        $this->assertNotEmpty(Mapper::validate(
            Mapper::TYPE_CHANGE,
            ['decision' => 'APPROVED', 'requestedMedicationId' => '99'],
            $cache
        ));

        $this->expectException(InvalidArgumentException::class);
        Mapper::changeResponse(
            self::CONTEXT,
            'n-change-1',
            ['decision' => 'APPROVED', 'requestedMedicationId' => '99'],
            $cache
        );
    }

    public function testChangeDenialNeedsAnExplanation(): void
    {
        $cache = Mapper::detailCache(Mapper::TYPE_CHANGE, $this->changeResponse());
        $input = ['decision' => 'DENIED', 'requestedMedicationId' => '7'];

        $this->assertNotEmpty(Mapper::validate(Mapper::TYPE_CHANGE, $input, $cache));
        $this->assertSame([], Mapper::validate(Mapper::TYPE_CHANGE, $input + ['notes' => 'Keep brand'], $cache));
    }


    /** @return array<string, mixed> /v1/pharmacy-initiated/new-rx/get-details */
    private function newRxResponse(): array
    {
        return [
            'rxNewRequestFromPharmacy' => [
                'patient' => [
                    'firstName' => 'Test',
                    'middleName' => 'Q',
                    'lastName' => 'Patient',
                    'dateOfBirth' => '1980-01-01',
                    'genderType' => 'F',
                    'phone' => '5550000000',
                    'address' => ['addressLine1' => '1 Test St', 'city' => 'Testville', 'stateProvince' => 'CA',
                        'postalCode' => '90000'],
                ],
                'pharmacy' => ['businessName' => 'Test Pharmacy', 'identification' => ['ncpdpid' => '0000000']],
                'medicationRequested' => [
                    'drugDescription' => 'TEST-DRUG 10 MG tablet',
                    'drugCoded' => ['productCode' => ['code' => '00000000000', 'qualifier' => 'ND']],
                    'quantity' => ['value' => '30', 'codeListQualifier' => '38'],
                    'daysSupply' => '30',
                    'sig' => ['sigText' => 'Synthetic directions for test use only'],
                    'numberOfRefills' => '2',
                ],
            ],
            'erxMessage' => 'Pharmacy-initiated New Rx request pending physician response',
            'isNewPatient' => true,
            'erxPatientPKUuid' => 'erx-patient-new-0001',
            'sourcePatientRecordId' => 'source-0001',
        ];
    }

    public function testNewRxRequestsAreRecognisedApartFromNewRxVerification(): void
    {
        $this->assertSame(Mapper::TYPE_NEW_RX, Mapper::typeForNotification('NEW_RX_REQUEST'));
        $this->assertNull(Mapper::typeForNotification('RX_NEW'), 'the verification status of a script sent here');
    }

    public function testNewRxDetailsAreFlattenedForTheScreen(): void
    {
        $details = Mapper::newRxDetails($this->newRxResponse());

        $this->assertSame('Test Patient', $details['patient']['name']);
        $this->assertSame('1980-01-01', $details['patient']['dob']);
        $this->assertSame('5550000000', $details['patient']['phone']);
        $this->assertSame('1 Test St, Testville, CA, 90000', $details['patient']['address']);
        $this->assertSame('Test Pharmacy', $details['pharmacy']['name']);
        $this->assertSame('TEST-DRUG 10 MG tablet', $details['requested']['description']);
        $this->assertSame('00000000000', $details['requested']['ndc']);
        $this->assertSame('2', $details['requested']['refills']);
        $this->assertTrue($details['isNewPatient']);
        $this->assertSame('erx-patient-new-0001', $details['erxPatientUuid']);
        $this->assertSame(['APPROVED', 'DENIED'], $details['decisions']);
        $this->assertArrayHasKey('AA', $details['denialReasons']);
    }

    public function testNewRxCacheKeepsThePatientForCreatingAChart(): void
    {
        $cache = Mapper::detailCache(Mapper::TYPE_NEW_RX, $this->newRxResponse());

        $this->assertSame(Mapper::TYPE_NEW_RX, $cache['type']);
        $this->assertSame('Patient', $cache['patient']['lastName']);
        $this->assertSame('F', $cache['patient']['genderType']);
        $this->assertSame('erx-patient-new-0001', $cache['erxPatientUuid']);
        $this->assertCount(1, $cache['requested']);
        $this->assertSame('TEST-DRUG 10 MG tablet', $cache['requested'][0]['drugDescription']);

        $empty = Mapper::detailCache(Mapper::TYPE_NEW_RX, []);
        $this->assertSame([], $empty['requested']);
        $this->assertSame([], $empty['patient']);
    }

    public function testNewRxResponseCarriesTheDecisionAndNothingAboutTheMedication(): void
    {
        $cache = Mapper::detailCache(Mapper::TYPE_NEW_RX, $this->newRxResponse());

        $approve = ['decision' => 'APPROVED', 'notes' => 'Supply brand if generic unavailable.'];
        $this->assertSame([], Mapper::validate(Mapper::TYPE_NEW_RX, $approve, $cache));
        $payload = Mapper::newRxResponse(self::CONTEXT, 'n-newrx-1', $approve, 'America/Chicago');
        $this->assertSame('n-newrx-1', $payload['erxNotificationPkUuid']);
        $this->assertSame('America/Chicago', $payload['timeZone']);
        $this->assertSame('clinician-test-0001', $payload['erxClinicianUserPKUuid']);
        $this->assertSame(
            ['responseCode' => 'APPROVED', 'notesToPharmacist' => 'Supply brand if generic unavailable.'],
            $payload['rxPharmacyIntiNewRxInfo']
        );
        $this->assertArrayNotHasKey('medicationResponse', $payload);

        $deny = ['decision' => 'DENIED', 'deniedReason' => 'aa', 'notes' => ''];
        $this->assertSame([], Mapper::validate(Mapper::TYPE_NEW_RX, $deny, $cache));
        $payload = Mapper::newRxResponse(self::CONTEXT, 'n-newrx-1', $deny, 'UTC');
        $this->assertSame(
            ['responseCode' => 'DENIED', 'deniedResponseCode' => 'AA'],
            $payload['rxPharmacyIntiNewRxInfo']
        );
    }

    public function testNewRxDecisionsAreChecked(): void
    {
        $cache = Mapper::detailCache(Mapper::TYPE_NEW_RX, $this->newRxResponse());

        $this->assertNotEmpty(
            Mapper::validate(Mapper::TYPE_NEW_RX, ['decision' => 'DENIED'], $cache),
            'needs a reason'
        );
        $this->assertNotEmpty(
            Mapper::validate(Mapper::TYPE_NEW_RX, ['decision' => 'DENIED', 'deniedReason' => 'ZZ'], $cache)
        );
        $this->assertNotEmpty(
            Mapper::validate(Mapper::TYPE_NEW_RX, ['decision' => 'APPROVED_WITH_CHANGES'], $cache),
            'no changed form of a new Rx request'
        );
        $this->assertNotEmpty(
            Mapper::validate(Mapper::TYPE_NEW_RX, ['decision' => 'APPROVED', 'notes' => str_repeat('x', 211)], $cache),
            'notes are bounded by the backend'
        );
        $this->assertNotEmpty(
            Mapper::validate(
                Mapper::TYPE_NEW_RX,
                ['decision' => 'APPROVED'],
                Mapper::detailCache(Mapper::TYPE_NEW_RX, [])
            ),
            'nothing to approve'
        );
        $this->assertNotEmpty(
            Mapper::validate(Mapper::TYPE_NEW_RX, ['decision' => 'APPROVED'], []),
            'not reviewed'
        );
    }

    public function testApprovedNewRxRequestPutsTheRequestedMedicationOnTheChart(): void
    {
        $cache = Mapper::detailCache(Mapper::TYPE_NEW_RX, $this->newRxResponse());
        $approved = Mapper::newRxResponse(self::CONTEXT, 'n-newrx-1', ['decision' => 'APPROVED'], 'UTC');
        $denied = Mapper::newRxResponse(
            self::CONTEXT,
            'n-newrx-1',
            ['decision' => 'DENIED', 'deniedReason' => 'AF'],
            'UTC'
        );

        $this->assertSame([
            'drugName' => 'TEST-DRUG 10 MG tablet',
            'ndcNumber' => '00000000000',
            'sig' => 'Synthetic directions for test use only',
            'quantity' => '30',
            'refills' => '2',
        ], Mapper::approvedMedication(Mapper::TYPE_NEW_RX, $approved, $cache));
        $this->assertNull(Mapper::approvedMedication(Mapper::TYPE_NEW_RX, $denied, $cache));
        $this->assertNull(
            Mapper::approvedMedication(Mapper::TYPE_NEW_RX, $approved, Mapper::detailCache(Mapper::TYPE_RENEWAL, [])),
            'a cache of another type is not trusted'
        );
    }
}
