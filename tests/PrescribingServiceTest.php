<?php

/**
 * Response flattening for the prescribing screen. All fixtures are synthetic.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Client\BackendClient;
use LogiCoy\ERx\Prescription\PrescribingService;
use PHPUnit\Framework\TestCase;

class PrescribingServiceTest extends TestCase
{
    /** @return array<string, mixed> */
    private function credential(): array
    {
        return [
            'id' => 1,
            'erx_practice_uuid' => 'practice-uuid',
            'erx_location_uuid' => 'location-uuid',
            'erx_clinician_user_pk_uuid' => 'clinician-pk-uuid',
        ];
    }

    private function backendReturning(array $response, ?array &$captured = null): BackendClient
    {
        $backend = $this->createMock(BackendClient::class);
        $backend->method('post')->willReturnCallback(
            function (string $endpoint, array $payload) use ($response, &$captured): array {
                $captured = ['endpoint' => $endpoint, 'payload' => $payload];
                return $response;
            }
        );

        return $backend;
    }

    public function testDrugSearchSendsTheIdentifiersTheBackendRequires(): void
    {
        $captured = null;
        $service = new PrescribingService($this->backendReturning([], $captured));

        $service->searchDrugsByName($this->credential(), 'amoxicillin');

        $this->assertSame(BackendClient::ENDPOINT_DRUG_SEARCH_BY_NAME, $captured['endpoint']);
        $this->assertSame('amoxicillin', $captured['payload']['productName']);
        $this->assertSame('practice-uuid', $captured['payload']['erxPracticePKUuid']);
        $this->assertSame('location-uuid', $captured['payload']['erxPracticeServiceLocPKUuid']);
        // Search uses the clinician PK uuid, not the draft endpoints' user uuid.
        $this->assertSame('clinician-pk-uuid', $captured['payload']['erxClinicianUserPKUuid']);
        $this->assertNotEmpty($captured['payload']['transactionUuid']);
    }

    public function testSearchStillIdentifiesTheClinicianFromTheDraftFieldAlone(): void
    {
        // A credential loaded by hand may carry only erx_clinician_user_uuid.
        // Posting a blank erxClinicianUserPKUuid gets the whole search rejected
        // with "Clinician user PK UUID is mandatory".
        $captured = null;
        $service = new PrescribingService($this->backendReturning([], $captured));

        $service->searchDrugsByNdc([
            'id' => 1,
            'erx_practice_uuid' => 'practice-uuid',
            'erx_location_uuid' => 'location-uuid',
            'erx_clinician_user_uuid' => 'clinician-uuid',
        ], '00093416173');

        $this->assertSame('clinician-uuid', $captured['payload']['erxClinicianUserPKUuid']);
    }

    public function testDrugSearchFlattensResults(): void
    {
        $service = new PrescribingService($this->backendReturning([
            'medicationSearchResults' => [[
                'ndcNumber' => '00093416173',
                'searchDrugDesc' => 'AMOXICILLIN 500 MG capsule',
                'searchDrugDescToSend' => 'AMOXICILLIN 500 MG CAPSULE',
                'genericDrugName' => 'amoxicillin',
                'drugStrength' => '500 MG',
                'drugForm' => 'capsule',
                'scheduleClassType' => '0',
            ]],
        ]));

        $drugs = $service->searchDrugsByName($this->credential(), 'amox');

        $this->assertCount(1, $drugs);
        $this->assertSame('00093416173', $drugs[0]['ndcNumber']);
        // drugName is what gets echoed back on the prescription.
        $this->assertSame('AMOXICILLIN 500 MG CAPSULE', $drugs[0]['drugName']);
        $this->assertSame('AMOXICILLIN 500 MG capsule', $drugs[0]['displayName']);
        $this->assertFalse($drugs[0]['isControlled']);
    }

    public function testControlledSubstancesAreFlagged(): void
    {
        // EPCS signing lives in the LogiCoy backend, not here, so the screen has
        // to be able to warn rather than silently transmit.
        $service = new PrescribingService($this->backendReturning([
            'medicationSearchResults' => [[
                'ndcNumber' => '00406012301',
                'productName' => 'SYNTHETIC-CII 10 MG tablet',
                'scheduleClassType' => '2',
                'scheduleClassTypeWarningMessage' => 'Schedule II requires EPCS',
            ]],
        ]));

        $drugs = $service->searchDrugsByName($this->credential(), 'synthetic');

        $this->assertTrue($drugs[0]['isControlled']);
        $this->assertSame('2', $drugs[0]['scheduleClass']);
        $this->assertSame('Schedule II requires EPCS', $drugs[0]['controlledWarning']);
    }

    public function testScheduleZeroAndBlankAreNotControlled(): void
    {
        foreach (['0', '', null] as $schedule) {
            $service = new PrescribingService($this->backendReturning([
                'medicationSearchResults' => [['ndcNumber' => '1', 'scheduleClassType' => $schedule]],
            ]));
            $drugs = $service->searchDrugsByName($this->credential(), 'x');
            $this->assertFalse($drugs[0]['isControlled'], 'schedule ' . var_export($schedule, true));
        }
    }

    public function testPrescriptionLegendDrugsAreNotControlled(): void
    {
        // What the backend actually returns for an ordinary drug: schedule "R"
        // and the warning flag off. Reading "R" as a schedule marked every
        // result controlled and disabled the form's save button for all of them.
        $service = new PrescribingService($this->backendReturning([
            'medicationSearchResults' => [[
                'ndcNumber' => '00093226701',
                'searchDrugDesc' => 'AMOXICILLIN 125 MG [Tablet, Chewable]',
                'scheduleClassType' => 'R',
                'scheduleClassTypeWarning' => false,
                'scheduleClassTypeWarningMessage' => null,
            ]],
        ]));

        $drugs = $service->searchDrugsByName($this->credential(), 'amox');

        $this->assertFalse($drugs[0]['isControlled']);
    }

    public function testTheBackendsOwnWarningFlagDecides(): void
    {
        $service = new PrescribingService($this->backendReturning([
            'medicationSearchResults' => [[
                'ndcNumber' => '00406012301',
                'scheduleClassType' => '2',
                'scheduleClassTypeWarning' => true,
                'scheduleClassTypeWarningMessage' => 'Schedule II requires EPCS',
            ]],
        ]));

        $drugs = $service->searchDrugsByName($this->credential(), 'x');

        $this->assertTrue($drugs[0]['isControlled']);
        $this->assertSame('Schedule II requires EPCS', $drugs[0]['controlledWarning']);
    }

    public function testAnUnrecognisedScheduleCodeCountsAsControlled(): void
    {
        // No warning flag and a code we do not know. Over-warning costs a
        // prescriber one check; under-warning would offer to transmit a
        // scheduled drug with no EPCS behind it.
        $service = new PrescribingService($this->backendReturning([
            'medicationSearchResults' => [['ndcNumber' => '1', 'scheduleClassType' => 'CIII']],
        ]));

        $this->assertTrue($service->searchDrugsByName($this->credential(), 'x')[0]['isControlled']);
    }

    public function testDrugSearchToleratesAnEmptyOrWrappedResponse(): void
    {
        $service = new PrescribingService($this->backendReturning([]));
        $this->assertSame([], $service->searchDrugsByName($this->credential(), 'x'));

        $wrapped = new PrescribingService($this->backendReturning([
            'data' => ['medicationSearchResults' => [['ndcNumber' => '123']]],
        ]));
        $this->assertCount(1, $wrapped->searchDrugsByName($this->credential(), 'x'));
    }

    public function testPharmacySearchMergesRetailAndMailOrder(): void
    {
        $service = new PrescribingService($this->backendReturning([
            'pharmacySearchResults' => [
                'pharmacyList' => [[
                    'organizationId' => '811873',
                    'organizationName' => 'Synthetic Community Pharmacy',
                    'city' => 'Austin',
                    'stateProvince' => 'TX',
                ]],
                'mailOrderList' => [[
                    'organizationId' => '900001',
                    'organizationName' => 'Synthetic Mail Order',
                ]],
            ],
        ]));

        $pharmacies = $service->searchPharmacies($this->credential(), ['city' => 'Austin']);

        $this->assertCount(2, $pharmacies);
        $this->assertFalse($pharmacies[0]['isMailOrder']);
        $this->assertSame('811873', $pharmacies[0]['organizationId']);
        $this->assertTrue($pharmacies[1]['isMailOrder']);
    }

    public function testPharmacySearchReadsBackendPharmacySearchDetailsShape(): void
    {
        // Shape per the backend's PharmacySearchDetails schema: results is an
        // array, the id is an int pharmacyOrgId, address fields are standardized*.
        $service = new PrescribingService($this->backendReturning([
            'pharmacySearchResults' => [[
                'pharmacyList' => [[
                    'pharmacyOrgId' => 439190,
                    'organizationName' => 'Synthetic Corner Pharmacy',
                    'standardizedAddressLine1' => '1 Test St',
                    'standardizedCity' => 'Testville',
                    'standardizedStateProvince' => 'CA',
                    'postalCode' => '90000',
                    'primaryTelephone' => '5550000000',
                ]],
                'mailOrderList' => [],
            ]],
        ]));

        $pharmacies = $service->searchPharmacies($this->credential(), ['organizationName' => 'synthetic']);

        $this->assertCount(1, $pharmacies);
        $this->assertSame('439190', $pharmacies[0]['organizationId']);
        $this->assertSame('1 Test St', $pharmacies[0]['address']);
        $this->assertSame('Testville', $pharmacies[0]['city']);
        $this->assertSame('CA', $pharmacies[0]['state']);
        $this->assertSame('5550000000', $pharmacies[0]['phone']);
    }

    public function testPharmacySearchSkipsEntriesWithoutAnId(): void
    {
        $service = new PrescribingService($this->backendReturning([
            'pharmacySearchResults' => [
                'pharmacyList' => [['organizationName' => 'Synthetic No Id Pharmacy']],
            ],
        ]));

        $this->assertSame([], $service->searchPharmacies($this->credential(), []));
    }

    public function testPharmacySearchDropsEmptyFilters(): void
    {
        $captured = null;
        $service = new PrescribingService($this->backendReturning([], $captured));

        $service->searchPharmacies($this->credential(), [
            'city' => 'Austin',
            'postalCode' => '',
            'organizationName' => null,
        ]);

        $filters = $captured['payload']['pharmacySearchFilters'];
        $this->assertSame(['city' => 'Austin'], $filters);
    }

    public function testControlledNdcLookupReadsTheMatchingResult(): void
    {
        $service = new PrescribingService($this->backendReturning([
            'medicationSearchResults' => [
                ['ndcNumber' => '00093-0150-01', 'scheduleClassType' => 'R', 'scheduleClassTypeWarning' => false],
                ['ndcNumber' => '00406-0552-01', 'scheduleClassType' => '2', 'scheduleClassTypeWarning' => true],
            ],
        ], $captured));

        $this->assertTrue($service->isControlledNdc($this->credential(), '0040605520 1'));
        $this->assertSame(BackendClient::ENDPOINT_DRUG_SEARCH_BY_NDC, $captured['endpoint']);
        $this->assertSame('00406055201', $captured['payload']['ndcNumber']);
        $this->assertFalse($service->isControlledNdc($this->credential(), '00093015001'));
        // An NDC the backend answers with other products for is unclassified;
        // a neighbour's schedule must never stand in for it.
        $this->assertNull($service->isControlledNdc($this->credential(), '99999999999'));
    }

    public function testControlledNdcLookupIsUnknownWhenNothingComesBack(): void
    {
        $backend = $this->createMock(BackendClient::class);
        $backend->expects($this->once())->method('post')->willReturn(['medicationSearchResults' => []]);
        $service = new PrescribingService($backend);

        $this->assertNull($service->isControlledNdc($this->credential(), '00093015001'));
        // No NDC at all is not worth a call.
        $this->assertNull($service->isControlledNdc($this->credential(), ''));
    }

    public function testControlledNdcLookupIsUnknownWhenTheResultCarriesNoSchedule(): void
    {
        // Neither the flag nor a code: the backend said nothing about the
        // schedule, and silence must not read as "not controlled".
        $service = new PrescribingService($this->backendReturning([
            'medicationSearchResults' => [['ndcNumber' => '00406055201', 'productName' => 'Something']],
        ]));
        $this->assertNull($service->isControlledNdc($this->credential(), '00406055201'));

        // The flag alone decides, as it does on the prescribing screen.
        $service = new PrescribingService($this->backendReturning([
            'medicationSearchResults' => [
                ['ndcNumber' => '00406055201', 'scheduleClassType' => null, 'scheduleClassTypeWarning' => false],
            ],
        ]));
        $this->assertFalse($service->isControlledNdc($this->credential(), '00406055201'));

        // A hyphenated NDC in the answer still matches the draft's digits.
        $service = new PrescribingService($this->backendReturning([
            'medicationSearchResults' => [
                ['ndcNumber' => '00406-0552-01', 'scheduleClassType' => '2', 'scheduleClassTypeWarning' => true],
            ],
        ]));
        $this->assertTrue($service->isControlledNdc($this->credential(), '00406055201'));
    }

    public function testTransactionIdsAreDistinctUuids(): void
    {
        $a = PrescribingService::newTransactionId();
        $b = PrescribingService::newTransactionId();

        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $a
        );
    }
}
