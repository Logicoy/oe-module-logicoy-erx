<?php

/**
 * Registration is idempotent. All fixtures are synthetic.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Client\BackendClient;
use LogiCoy\ERx\Client\BackendException;
use LogiCoy\ERx\Config\CredentialStore;
use LogiCoy\ERx\Config\ProviderProfileStore;
use LogiCoy\ERx\Config\ProvisioningService;
use PHPUnit\Framework\TestCase;

class ProvisioningServiceTest extends TestCase
{
    /** @return array<string, mixed> a provider scoped credential row */
    private function providerCredential(): array
    {
        return [
            'id' => 9,
            'scope_type' => CredentialStore::SCOPE_PROVIDER,
            'facility_id' => 3,
            'provider_user_id' => 42,
            'erx_practice_uuid' => 'practice-uuid',
            'erx_location_uuid' => 'location-uuid',
            'erx_clinician_user_pk_uuid' => 'clinician-pk-uuid',
        ];
    }

    /** @return array<string, mixed> a complete OpenEMR user record */
    private function user(): array
    {
        return [
            'fname' => 'Alicia', 'mname' => '', 'lname' => 'Sloan',
            'npi' => '1234567893', 'state_license_number' => 'TX-998877',
            'federaldrugid' => 'FS1234563',
            'phone' => '', 'phonew1' => '(512) 555-0100', 'phonecell' => '',
            'email' => 'alicia.sloan@example.test',
            'street' => '101 Congress Ave', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701-1234',
            'country_code' => 'US',
        ];
    }

    /** @return array<string, mixed> */
    private function profile(string $type): array
    {
        return [
            'user_id' => 42,
            'dob' => '1980-05-17',
            'gender' => 'F',
            'prescribing_type' => $type,
            'dea_expiration_date' => '2027-09-30',
            'dea_state' => 'TX',
            'has_ssn_last4' => true,
        ];
    }

    /**
     * The service with the database lookups replaced: the user record comes
     * from a fixture, the profile store is mocked, the backend is mocked.
     *
     * @param array<string, mixed>|null $user
     * @param array<string, mixed> $profile
     * @param array<int, array{endpoint: string, payload: array<string, mixed>}> $calls
     */
    private function service(
        ?array $user,
        array $profile,
        ?string $ssnLast4,
        array $response,
        ?array &$calls = null
    ): ProvisioningService {
        $calls = [];
        $backend = $this->createMock(BackendClient::class);
        $backend->method('post')->willReturnCallback(
            function (string $endpoint, array $payload) use ($response, &$calls): array {
                $calls[] = ['endpoint' => $endpoint, 'payload' => $payload];
                return $response;
            }
        );
        $profiles = $this->createMock(ProviderProfileStore::class);
        $profiles->method('get')->willReturn($profile);
        $profiles->method('getSsnLast4')->willReturn($ssnLast4);

        $store = $this->createMock(CredentialStore::class);

        return new class ($backend, $store, $profiles, $user) extends ProvisioningService {
            /** @param array<string, mixed>|null $user */
            public function __construct(
                BackendClient $backend,
                CredentialStore $store,
                ProviderProfileStore $profiles,
                private ?array $user
            ) {
                parent::__construct($backend, $store, $profiles);
            }

            protected function provider(int $userId): ?array
            {
                return $this->user;
            }
        };
    }

    public function testUpdateSendsTheEpcsDetailsUnderTheClinicianIdentifier(): void
    {
        $service = $this->service($this->user(), $this->profile('EPCS'), '1234', [
            'transactionStatus' => ['statusCode' => '200', 'status' => 'OK'],
        ], $calls);

        $outcome = $service->updateClinician(42, $this->providerCredential());

        $this->assertCount(1, $calls);
        $this->assertSame(BackendClient::ENDPOINT_CLINICIAN_UPDATE, $calls[0]['endpoint']);
        $payload = $calls[0]['payload'];
        $this->assertSame('practice-uuid', $payload['erxPracticePKUuid']);
        $this->assertSame('location-uuid', $payload['erxPracticeServiceLocPKUuid']);
        $this->assertNotEmpty($payload['transactionUuid']);

        $details = $payload['clinicianUserDetails'];
        $this->assertSame('clinician-pk-uuid', $details['erxClinicianUserPKUuid']);
        $this->assertSame('FS1234563', $details['dea']);
        $this->assertSame('TX', $details['deaState']);
        $this->assertSame('2027-09-30', $details['deaExpirationDate']);
        $this->assertSame('1980-05-17', $details['dateOfBirth']);
        $this->assertSame('F', $details['gender']);
        $this->assertSame('TX-998877', $details['stateLicenseNumber']);
        $this->assertSame('5125550100', $details['phone']);
        $this->assertSame('78701', $details['address']['postalCode']);
        $this->assertContains('CONTROLLED_SUBSTANCE', $details['serviceLevel']);
        $this->assertContains('NEW', $details['serviceLevel']);
        $this->assertContains('NEW_RX_REQUEST', $details['serviceLevel']);
        $this->assertContains('NEW_RX_RESPONSE_DENIED', $details['serviceLevel']);

        // Not in the update model, so never sent: the backend keeps them.
        $notInUpdateModel = ['firstName', 'lastName', 'email', 'npi', 'clinicianPrescribingTypeRef', 'ssnLast4Digits'];
        foreach ($notInUpdateModel as $absent) {
            $this->assertArrayNotHasKey($absent, $details, $absent);
        }
        // The old misspelling must not come back.
        $this->assertArrayNotHasKey('deaNumber', $details);

        $this->assertTrue($outcome['epcs']);
        $this->assertSame('clinician-pk-uuid', $outcome['clinician_uuid']);
    }

    public function testUpdateForAnErxPrescriberAsksForNoControlledSubstanceLevel(): void
    {
        $service = $this->service($this->user(), $this->profile('ERX'), null, [], $calls);

        $outcome = $service->updateClinician(42, $this->providerCredential());

        $this->assertNotContains('CONTROLLED_SUBSTANCE', $calls[0]['payload']['clinicianUserDetails']['serviceLevel']);
        $this->assertFalse($outcome['epcs']);
    }

    public function testUpdateIsReplaySafe(): void
    {
        $service = $this->service($this->user(), $this->profile('EPCS'), '1234', [], $calls);

        $service->updateClinician(42, $this->providerCredential());
        $service->updateClinician(42, $this->providerCredential());

        // Same request both times, apart from the transaction id, and nothing
        // stored locally to drift.
        $this->assertCount(2, $calls);
        unset($calls[0]['payload']['transactionUuid'], $calls[1]['payload']['transactionUuid']);
        $this->assertSame($calls[0]['payload'], $calls[1]['payload']);
    }

    public function testUpdateRefusesAnUnregisteredPrescriberWithoutACall(): void
    {
        $service = $this->service($this->user(), $this->profile('EPCS'), '1234', [], $calls);
        $credential = $this->providerCredential();
        unset($credential['erx_clinician_user_pk_uuid']);

        $this->expectException(BackendException::class);
        $this->expectExceptionMessage('Register the prescriber');
        try {
            $service->updateClinician(42, $credential);
        } finally {
            $this->assertSame([], $calls);
        }
    }

    public function testUpdateRefusesAnIncompleteEpcsProfileWithoutACall(): void
    {
        $profile = $this->profile('EPCS');
        $profile['dea_state'] = null;
        $service = $this->service($this->user(), $profile, null, [], $calls);

        try {
            $service->updateClinician(42, $this->providerCredential());
            $this->fail('expected a BackendException');
        } catch (BackendException $e) {
            $this->assertStringContainsString('DEA state', $e->getMessage());
            $this->assertStringContainsString('SSN last 4', $e->getMessage());
        }
        $this->assertSame([], $calls);
    }

    public function testUpdateSurfacesAFailureTheBackendReportsInsideA200(): void
    {
        $service = $this->service($this->user(), $this->profile('EPCS'), '1234', [
            'isValidationError' => true,
            'validationErrorInfo' => [['errorMessage' => 'Clinician Record do not exist']],
        ]);

        $this->expectException(BackendException::class);
        $this->expectExceptionMessage('Clinician Record do not exist');
        $service->updateClinician(42, $this->providerCredential());
    }

    public function testUpgradeToEpcsSendsTheDeaAndIdmeDetailsAndReturnsTheLink(): void
    {
        $service = $this->service($this->user(), $this->profile('EPCS'), '1234', [
            'idmeVerificationLink' => 'https://api.idmelabs.com/oauth/authorize?tracking_uuid=abc',
            'description' => 'Please use the provided ID.me identity verification link.',
        ], $calls);

        $outcome = $service->upgradeToEpcs(
            42,
            $this->providerCredential(),
            ' Dr.Example@example.test ',
            'https://ehr.example.test/moduleConfig.php?idme_returned=1&epcs=1'
        );

        $this->assertSame(BackendClient::ENDPOINT_CLINICIAN_UPGRADE_EPCS, $calls[0]['endpoint']);
        $payload = $calls[0]['payload'];
        $this->assertSame('clinician-pk-uuid', $payload['erxClinicianUserPKUuid']);
        $this->assertSame('practice-uuid', $payload['erxPracticePKUuid']);
        $this->assertSame('location-uuid', $payload['erxPracticeServiceLocPKUuid']);
        $this->assertSame('FS1234563', $payload['dea']);
        $this->assertSame('TX', $payload['deaState']);
        $this->assertSame('2027-09-30', $payload['deaExpirationDate']);
        $this->assertSame('1234', $payload['ssnLast4Digits']);
        $this->assertSame('Dr.Example@example.test', $payload['idmePrimaryEmailId']);
        $this->assertSame(
            'https://ehr.example.test/moduleConfig.php?idme_returned=1&epcs=1',
            $payload['clientRedirectionUrl']
        );
        $this->assertNotEmpty($payload['transactionUuid']);

        $this->assertSame('https://api.idmelabs.com/oauth/authorize?tracking_uuid=abc', $outcome['link']);
        $this->assertStringContainsString('ID.me', $outcome['description']);
    }

    public function testUpgradeToEpcsRefusesBeforeAnyCallWhenNotReady(): void
    {
        $cases = [
            'not EPCS locally' => [$this->user(), $this->profile('ERX'), '1234', 'dr@example.test', 'prescribing type'],
            'bad email' => [$this->user(), $this->profile('EPCS'), '1234', 'not-an-email', 'ID.me account'],
            'missing SSN' => [$this->user(), $this->profile('EPCS'), null, 'dr@example.test', 'SSN last 4'],
        ];
        foreach ($cases as $name => [$user, $profile, $ssn, $email, $expected]) {
            $service = $this->service($user, $profile, $ssn, [], $calls);
            try {
                $service->upgradeToEpcs(42, $this->providerCredential(), $email, 'https://ehr.example.test/x');
                $this->fail($name . ': expected a BackendException');
            } catch (BackendException $e) {
                $this->assertStringContainsString($expected, $e->getMessage(), $name);
            }
            $this->assertSame([], $calls, $name);
        }

        $service = $this->service($this->user(), $this->profile('EPCS'), '1234', [], $calls);
        $credential = $this->providerCredential();
        unset($credential['erx_clinician_user_pk_uuid']);
        $this->expectException(BackendException::class);
        $this->expectExceptionMessage('Register the prescriber');
        $service->upgradeToEpcs(42, $credential, 'dr@example.test', 'https://ehr.example.test/x');
    }

    public function testUpgradeToEpcsRefusesAnUnsafeLinkAndABackendFailure(): void
    {
        $credential = $this->providerCredential();
        foreach (['http://id.me/x', 'javascript:alert(1)', '/relative'] as $bad) {
            $service = $this->service($this->user(), $this->profile('EPCS'), '1234', [
                'idmeVerificationLink' => $bad,
            ]);
            try {
                $service->upgradeToEpcs(42, $credential, 'dr@example.test', 'https://ehr.example.test/');
                $this->fail('expected a BackendException for ' . $bad);
            } catch (BackendException $e) {
                $this->assertStringContainsString('unusable ID.me link', $e->getMessage(), $bad);
            }
        }

        $service = $this->service($this->user(), $this->profile('EPCS'), '1234', [
            'isValidationError' => true,
            'validationErrorInfo' => [['errorMessage' => 'Clinician is already an EPCS prescriber']],
        ]);
        $this->expectException(BackendException::class);
        $this->expectExceptionMessage('already an EPCS prescriber');
        $service->upgradeToEpcs(42, $this->providerCredential(), 'dr@example.test', 'https://ehr.example.test/x');
    }

    public function testUpgradeStatusTrustsOnlyAnExplicitTrue(): void
    {
        $service = $this->service($this->user(), $this->profile('EPCS'), '1234', [
            'requestStatus' => 'success',
            'verificationStatus' => 'SUCCESS',
            'message' => 'Upgradation is SUCCESS',
            'isEpcsPrescriber' => true,
            'clinicianPrescribingTypeRef' => 'EPCS',
        ], $calls);
        $status = $service->upgradeToEpcsStatus($this->providerCredential());

        $this->assertSame(BackendClient::ENDPOINT_CLINICIAN_UPGRADE_EPCS_STATUS, $calls[0]['endpoint']);
        $this->assertSame('clinician-pk-uuid', $calls[0]['payload']['erxClinicianUserPKUuid']);
        $this->assertArrayNotHasKey('erxPracticeServiceLocPKUuid', $calls[0]['payload']);
        $this->assertTrue($status['is_epcs']);
        $this->assertSame('SUCCESS', $status['request_status']);
        $this->assertSame('EPCS', $status['prescribing_type']);

        // "true" as a string, "yes", or a status word alone never counts.
        foreach ([['isEpcsPrescriber' => 'true'], ['isEpcsPrescriber' => 1], ['requestStatus' => 'SUCCESS']] as $body) {
            $service = $this->service($this->user(), $this->profile('EPCS'), '1234', $body);
            $status = $service->upgradeToEpcsStatus($this->providerCredential());
            $this->assertFalse($status['is_epcs'], json_encode($body));
        }
    }

    public function testServiceLevelsCarryControlledSubstanceOnlyForEpcs(): void
    {
        $base = [
            'NEW', 'CANCEL', 'CHANGE', 'REFILL', 'RX_FILL', 'RX_FILL_INDICATOR_CHANGE',
            'NEW_RX_REQUEST', 'NEW_RX_RESPONSE_DENIED',
        ];
        $this->assertSame($base, ProvisioningService::serviceLevels(false));
        $this->assertSame(array_merge($base, ['CONTROLLED_SUBSTANCE']), ProvisioningService::serviceLevels(true));
    }

    public function testARegisteredPrescriberIsRecognisedFromEitherStoredName(): void
    {
        // Registration stores the one identifier the backend returned under
        // both names, so either alone means "already registered".
        $this->assertSame(
            ['erx_clinician_user_uuid' => 'clinician-uuid', 'erx_clinician_user_pk_uuid' => 'clinician-uuid'],
            ProvisioningService::clinicianIdentifiers(['erx_clinician_user_pk_uuid' => 'clinician-uuid'])
        );

        $this->assertSame(
            ['erx_clinician_user_uuid' => 'clinician-uuid', 'erx_clinician_user_pk_uuid' => 'clinician-uuid'],
            ProvisioningService::clinicianIdentifiers(['erx_clinician_user_uuid' => 'clinician-uuid'])
        );
    }

    public function testTwoDistinctStoredIdentifiersAreBothKept(): void
    {
        $this->assertSame(
            ['erx_clinician_user_uuid' => 'draft-uuid', 'erx_clinician_user_pk_uuid' => 'send-uuid'],
            ProvisioningService::clinicianIdentifiers([
                'erx_clinician_user_uuid' => 'draft-uuid',
                'erx_clinician_user_pk_uuid' => 'send-uuid',
            ])
        );
    }

    public function testAProviderRowCarryingNoClinicianIsNotRegistered(): void
    {
        // A provider row also holds a prescriber's own client id, so its mere
        // existence must not suppress the registration call.
        foreach (
            [
                [],
                ['client_id' => 'a-client-id'],
                ['erx_clinician_user_uuid' => '', 'erx_clinician_user_pk_uuid' => null],
                // Written by earlier versions before the column took a real uuid.
                ['erx_clinician_user_pk_uuid' => 'pending'],
            ] as $row
        ) {
            $this->assertNull(
                ProvisioningService::clinicianIdentifiers($row),
                'row ' . json_encode($row)
            );
        }
    }
}
