<?php

/**
 * ID.me verification flow. The backend is mocked; all fixtures are synthetic.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use DateTimeImmutable;
use LogiCoy\ERx\Client\BackendClient;
use LogiCoy\ERx\Client\BackendException;
use LogiCoy\ERx\Identity\IdmeVerificationService as Service;
use PHPUnit\Framework\TestCase;

class IdmeVerificationServiceTest extends TestCase
{
    private const RETURN_URL = 'https://ehr.example.test/openemr/idme.php?facility_id=3&returned=1';

    /** @return array<string, mixed> */
    private function credential(): array
    {
        return [
            'id' => 7,
            'facility_id' => 3,
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

    public function testInitiateSendsTheIdentifiersAndReturnsTheLink(): void
    {
        $store = new InMemoryIdmeVerificationStore();
        $service = new Service($this->backendReturning([
            'idmeVerificationLink' => 'https://api.id.me/oauth/authorize?state=abc',
        ], $captured), $store);

        $link = $service->initiate($this->credential(), 42, ' Dr.Example@example.test ', self::RETURN_URL);

        $this->assertSame('https://api.id.me/oauth/authorize?state=abc', $link);
        $this->assertSame(BackendClient::ENDPOINT_IDME_INITIATE, $captured['endpoint']);
        $payload = $captured['payload'];
        $this->assertSame('clinician-pk-uuid', $payload['erxClinicianUserPKUuid']);
        $this->assertSame('practice-uuid', $payload['erxPracticePKUuid']);
        $this->assertSame('location-uuid', $payload['erxPracticeServiceLocPKUuid']);
        $this->assertSame('Dr.Example@example.test', $payload['idmePrimaryEmailId']);
        $this->assertSame(self::RETURN_URL, $payload['clientRedirectionUrl']);
        $this->assertNotEmpty($payload['transactionUuid']);

        // Recorded as started, under the prescriber's registration.
        $this->assertSame('PENDING', $store->find(3, 42)['verification_status']);
        $this->assertSame('Dr.Example@example.test', $store->find(3, 42)['idme_email']);
    }

    public function testInitiateRefusesAnUnregisteredPrescriberWithoutACall(): void
    {
        $backend = $this->createMock(BackendClient::class);
        $backend->expects($this->never())->method('post');
        $service = new Service($backend, new InMemoryIdmeVerificationStore());

        $this->expectException(BackendException::class);
        $this->expectExceptionMessage('Register the prescriber');
        $service->initiate(['facility_id' => 3], 42, 'dr@example.test', self::RETURN_URL);
    }

    public function testInitiateRefusesABadEmailWithoutACall(): void
    {
        $backend = $this->createMock(BackendClient::class);
        $backend->expects($this->never())->method('post');
        $service = new Service($backend, new InMemoryIdmeVerificationStore());

        $this->expectException(BackendException::class);
        $service->initiate($this->credential(), 42, 'not-an-email', self::RETURN_URL);
    }

    public function testInitiateRefusesAnEmailAnotherPrescriberHolds(): void
    {
        $store = new InMemoryIdmeVerificationStore();
        $store->recordStatus(3, 99, 'shared@example.test', 'VERIFIED', '2027-01-01', '');
        $backend = $this->createMock(BackendClient::class);
        $backend->expects($this->never())->method('post');

        $this->expectException(BackendException::class);
        $this->expectExceptionMessage('another prescriber');
        (new Service($backend, $store))->initiate($this->credential(), 42, 'SHARED@example.test', self::RETURN_URL);
    }

    public function testInitiateDoesNotRecordWhenTheBackendReportsAFailure(): void
    {
        $store = new InMemoryIdmeVerificationStore();
        $service = new Service($this->backendReturning([
            'isValidationError' => true,
            'validationErrorInfo' => [['errorMessage' => 'Email already registered']],
        ]), $store);

        try {
            $service->initiate($this->credential(), 42, 'dr@example.test', self::RETURN_URL);
            $this->fail('expected a BackendException');
        } catch (BackendException $e) {
            $this->assertStringContainsString('Email already registered', $e->getMessage());
        }
        $this->assertNull($store->find(3, 42));
    }

    public function testInitiateRefusesAnythingButAnHttpsLink(): void
    {
        foreach (['', 'javascript:alert(1)', 'http://id.me/x', '/relative'] as $bad) {
            $store = new InMemoryIdmeVerificationStore();
            $service = new Service($this->backendReturning(['idmeVerificationLink' => $bad]), $store);
            try {
                $service->initiate($this->credential(), 42, 'dr@example.test', self::RETURN_URL);
                $this->fail('expected a BackendException for ' . $bad);
            } catch (BackendException $e) {
                $this->assertNull($store->find(3, 42), $bad);
            }
        }
    }

    public function testCheckStatusRecordsWhatTheBackendAnswered(): void
    {
        $store = new InMemoryIdmeVerificationStore();
        $store->recordInitiated(3, 42, 'dr@example.test');
        $service = new Service($this->backendReturning([
            'verificationStatus' => 'VERIFIED',
            'verificationExpDate' => '2027-03-15T00:00:00Z',
            'message' => 'Identity verified',
        ], $captured), $store);

        $result = $service->checkStatus($this->credential(), 42, 'dr@example.test');

        $this->assertSame(BackendClient::ENDPOINT_IDME_STATUS, $captured['endpoint']);
        $this->assertSame('dr@example.test', $captured['payload']['idmePrimaryEmailId']);
        $this->assertSame(
            ['status' => 'VERIFIED', 'expires_on' => '2027-03-15', 'message' => 'Identity verified'],
            $result
        );
        $this->assertSame('VERIFIED', $store->find(3, 42)['verification_status']);
        $this->assertSame('2027-03-15', $store->find(3, 42)['verification_exp_date']);
    }

    public function testCheckStatusIsIdempotent(): void
    {
        $store = new InMemoryIdmeVerificationStore();
        $service = new Service($this->backendReturning([
            'verificationStatus' => 'VERIFIED',
            'verificationExpDate' => '2027-03-15',
        ]), $store);

        $service->checkStatus($this->credential(), 42, 'dr@example.test');
        $service->checkStatus($this->credential(), 42, 'dr@example.test');

        $this->assertCount(1, $store->rows);
    }

    public function testAnUnknownStatusWordNeverReadsAsVerified(): void
    {
        foreach (['', 'verified-ish', 'APPROVED', 'SUCCESS'] as $word) {
            $this->assertSame('NOT_VERIFIED', Service::parseStatus(['verificationStatus' => $word])['status'], $word);
        }
        $this->assertSame('VERIFIED', Service::parseStatus(['verificationStatus' => 'verified'])['status']);
        $this->assertSame('EXPIRED', Service::parseStatus(['verificationStatus' => 'EXPIRED'])['status']);
    }

    public function testStandingWithNothingStoredIsNotVerified(): void
    {
        $standing = Service::standing(null);
        $this->assertSame('NOT_VERIFIED', $standing['status']);
        $this->assertFalse($standing['expiring_soon']);
    }

    public function testStandingTrustsVerifiedOnlyUntilTheExpiryDate(): void
    {
        $row = ['verification_status' => 'VERIFIED', 'verification_exp_date' => '2026-12-01'];

        $before = Service::standing($row, new DateTimeImmutable('2026-06-01'));
        $this->assertSame('VERIFIED', $before['status']);
        $this->assertFalse($before['expiring_soon']);
        $this->assertSame(183, $before['days_left']);

        $onTheDay = Service::standing($row, new DateTimeImmutable('2026-12-01'));
        $this->assertSame('VERIFIED', $onTheDay['status']);
        $this->assertTrue($onTheDay['expiring_soon']);

        $after = Service::standing($row, new DateTimeImmutable('2026-12-02'));
        $this->assertSame('EXPIRED', $after['status']);
        $this->assertFalse($after['expiring_soon']);
    }

    public function testStandingWarnsThirtyDaysAhead(): void
    {
        $row = ['verification_status' => 'VERIFIED', 'verification_exp_date' => '2026-12-01'];

        $this->assertFalse(Service::standing($row, new DateTimeImmutable('2026-10-31'))['expiring_soon']);
        $this->assertTrue(Service::standing($row, new DateTimeImmutable('2026-11-01'))['expiring_soon']);
    }

    public function testAPendingRowStaysPending(): void
    {
        $standing = Service::standing(['verification_status' => 'PENDING', 'verification_exp_date' => null]);
        $this->assertSame('PENDING', $standing['status']);
        $this->assertNull($standing['days_left']);
    }
}
