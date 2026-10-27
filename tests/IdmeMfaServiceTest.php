<?php

/**
 * ID.me MFA flow. The backend is mocked; all fixtures are synthetic.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use DateTimeImmutable;
use DateTimeZone;
use LogiCoy\ERx\Client\BackendClient;
use LogiCoy\ERx\Client\BackendException;
use LogiCoy\ERx\Identity\IdmeMfaService as Service;
use PHPUnit\Framework\TestCase;

class IdmeMfaServiceTest extends TestCase
{
    private const METHODS = [
        ['uuid' => 'method-auth', 'name' => 'Authenticator', 'option' => 'generator', 'label' => 'Code Generator'],
        ['uuid' => 'method-sms', 'name' => 'SMS', 'option' => 'sms', 'label' => 'Text to ***-0100'],
        ['uuid' => 'method-push', 'name' => 'Push Notification', 'option' => 'push', 'label' => 'ID.me app'],
    ];

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

    /**
     * A backend answering each endpoint from a map, recording every call.
     *
     * @param array<string, array<string, mixed>> $byEndpoint
     * @param array<int, array{endpoint: string, payload: array<string, mixed>}> $calls
     */
    private function backend(array $byEndpoint, ?array &$calls = null): BackendClient
    {
        $calls = [];
        $backend = $this->createMock(BackendClient::class);
        $backend->method('post')->willReturnCallback(
            function (string $endpoint, array $payload) use ($byEndpoint, &$calls): array {
                $calls[] = ['endpoint' => $endpoint, 'payload' => $payload];
                return $byEndpoint[$endpoint] ?? [];
            }
        );

        return $backend;
    }

    /** A future time the way the backend writes it. */
    private function future(string $modify = '+8 hours'): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify($modify)->format('Y-m-d\TH:i:s\Z');
    }

    /** A future time the way the store holds it. */
    private function futureStored(string $modify = '+8 hours'): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify($modify)->format('Y-m-d H:i:s');
    }

    private function storeWithWaitingSession(
        ?string $expiresAt = null,
        string $option = 'generator'
    ): InMemoryIdmeMfaSessionStore {
        $store = new InMemoryIdmeMfaSessionStore();
        $store->recordStarted(
            3,
            42,
            ['uuid' => 'method-x', 'name' => $option === 'push' ? 'Push Notification' : 'Authenticator',
                'option' => $option, 'label' => ''],
            'session-1',
            Service::STATUS_WAITING,
            $expiresAt ?? $this->futureStored(),
            ''
        );
        $store->writes = [];

        return $store;
    }

    public function testListMethodsSendsTheIdentifiersAndFlagsPush(): void
    {
        $service = new Service($this->backend([
            BackendClient::ENDPOINT_IDME_MFA_METHODS => [
                'idmeMFAList' => self::METHODS + [3 => 'junk', 4 => ['name' => 'no uuid']],
            ],
        ], $calls), new InMemoryIdmeMfaSessionStore());

        $methods = $service->listMethods($this->credential());

        $this->assertSame(BackendClient::ENDPOINT_IDME_MFA_METHODS, $calls[0]['endpoint']);
        $payload = $calls[0]['payload'];
        $this->assertSame('clinician-pk-uuid', $payload['erxClinicianUserPKUuid']);
        $this->assertSame('practice-uuid', $payload['erxPracticePKUuid']);
        $this->assertSame('location-uuid', $payload['erxPracticeServiceLocPKUuid']);
        $this->assertNotEmpty($payload['transactionUuid']);

        $this->assertCount(3, $methods);
        $this->assertSame(['method-auth', 'method-sms', 'method-push'], array_column($methods, 'uuid'));
        $this->assertSame([false, false, true], array_column($methods, 'isPush'));
    }

    public function testListMethodsRefusesAnUnregisteredPrescriberWithoutACall(): void
    {
        $backend = $this->createMock(BackendClient::class);
        $backend->expects($this->never())->method('post');

        $this->expectException(BackendException::class);
        $this->expectExceptionMessage('Register the prescriber');
        (new Service($backend, new InMemoryIdmeMfaSessionStore()))->listMethods(['facility_id' => 3]);
    }

    public function testListMethodsSurfacesABackendFailure(): void
    {
        $service = new Service($this->backend([
            BackendClient::ENDPOINT_IDME_MFA_METHODS => [
                'isValidationError' => true,
                'validationErrorInfo' => [['errorMessage' => 'Prescriber is not ID.me verified']],
            ],
        ]), new InMemoryIdmeMfaSessionStore());

        $this->expectException(BackendException::class);
        $this->expectExceptionMessage('not ID.me verified');
        $service->listMethods($this->credential());
    }

    public function testStartRecordsAWaitingSessionWithItsExpiryInUtc(): void
    {
        $store = new InMemoryIdmeMfaSessionStore();
        $service = new Service($this->backend([
            BackendClient::ENDPOINT_IDME_MFA_METHODS => ['idmeMFAList' => self::METHODS],
            BackendClient::ENDPOINT_IDME_MFA_START => [
                'idmeStartMFAResponse' => [
                    'uuid' => 'session-1',
                    'status' => 'waiting',
                    // Given with an offset: stored as the same instant in UTC.
                    'expires' => '2027-03-16T10:08:37-05:00',
                ],
            ],
        ], $calls), $store);

        $standing = $service->start($this->credential(), 42, 'method-sms');

        $this->assertSame(BackendClient::ENDPOINT_IDME_MFA_START, $calls[1]['endpoint']);
        $this->assertSame('method-sms', $calls[1]['payload']['idmeMFAMethodTypeUuid']);

        $row = $store->find(3, 42);
        $this->assertSame('session-1', $row['session_uuid']);
        $this->assertSame('WAITING', $row['status']);
        $this->assertSame('2027-03-16 15:08:37', $row['expires_at']);
        $this->assertSame('SMS', $row['mfa_method_name']);

        $this->assertSame('WAITING', $standing['status']);
        $this->assertFalse($standing['active']);
        $this->assertFalse($standing['is_push']);
        $this->assertGreaterThan(0, $standing['seconds_left']);
    }

    public function testStartRefusesAMethodNotOnThePrescribersAccount(): void
    {
        $store = new InMemoryIdmeMfaSessionStore();
        $service = new Service($this->backend([
            BackendClient::ENDPOINT_IDME_MFA_METHODS => ['idmeMFAList' => self::METHODS],
            BackendClient::ENDPOINT_IDME_MFA_START => [
                'idmeStartMFAResponse' => ['uuid' => 'x', 'status' => 'waiting'],
            ],
        ], $calls), $store);

        try {
            $service->start($this->credential(), 42, 'someone-elses-method');
            $this->fail('expected a BackendException');
        } catch (BackendException $e) {
            $this->assertStringContainsString('Choose one of the MFA methods', $e->getMessage());
        }
        // Listed, but never started.
        $this->assertSame([BackendClient::ENDPOINT_IDME_MFA_METHODS], array_column($calls, 'endpoint'));
        $this->assertNull($store->find(3, 42));
    }

    public function testStartWithoutASessionUuidRecordsNothing(): void
    {
        $store = new InMemoryIdmeMfaSessionStore();
        $service = new Service($this->backend([
            BackendClient::ENDPOINT_IDME_MFA_METHODS => ['idmeMFAList' => self::METHODS],
            BackendClient::ENDPOINT_IDME_MFA_START => ['idmeStartMFAResponse' => ['status' => 'waiting']],
        ]), $store);

        $this->expectException(BackendException::class);
        try {
            $service->start($this->credential(), 42, 'method-auth');
        } finally {
            $this->assertNull($store->find(3, 42));
        }
    }

    public function testStartReplacesThePreviousSession(): void
    {
        $store = new InMemoryIdmeMfaSessionStore();
        $store->recordStarted(3, 42, self::METHODS[0], 'old-session', 'ACCEPTED', $this->futureStored(), '');
        $service = new Service($this->backend([
            BackendClient::ENDPOINT_IDME_MFA_METHODS => ['idmeMFAList' => self::METHODS],
            BackendClient::ENDPOINT_IDME_MFA_START => [
                'idmeStartMFAResponse' => [
                    'uuid' => 'new-session', 'status' => 'waiting', 'expires' => $this->future(),
                ],
            ],
        ]), $store);

        $service->start($this->credential(), 42, 'method-push');

        $this->assertCount(1, $store->rows);
        $this->assertSame('new-session', $store->find(3, 42)['session_uuid']);
        $this->assertSame('WAITING', $store->find(3, 42)['status']);
    }

    public function testCompleteSubmitsTheCodeAndRecordsAcceptance(): void
    {
        $store = $this->storeWithWaitingSession();
        $expires = $this->future('+10 hours');
        $service = new Service($this->backend([
            BackendClient::ENDPOINT_IDME_MFA_COMPLETE => [
                'idmeCompleteMFAResponse' => ['uuid' => 'session-1', 'status' => 'accepted', 'expires' => $expires],
            ],
        ], $calls), $store);

        $standing = $service->complete($this->credential(), 42, ' 123 456 ');

        $this->assertSame(BackendClient::ENDPOINT_IDME_MFA_COMPLETE, $calls[0]['endpoint']);
        $this->assertSame('session-1', $calls[0]['payload']['idmeStartMFAResponseUuid']);
        $this->assertSame('123456', $calls[0]['payload']['idmeVerificationCode']);

        $this->assertSame('ACCEPTED', $standing['status']);
        $this->assertTrue($standing['active']);
        $this->assertSame('ACCEPTED', $store->find(3, 42)['status']);
        $this->assertNotEmpty($store->find(3, 42)['accepted_at']);
        // The expiry given on completion replaces the one given at start.
        $this->assertSame(
            (new DateTimeImmutable($expires))->getTimestamp(),
            (new DateTimeImmutable($standing['expires_at']))->getTimestamp()
        );
    }

    public function testCompleteWithAWrongCodeIsRecordedAsFailedWithTheReason(): void
    {
        $store = $this->storeWithWaitingSession();
        $service = new Service($this->backend([
            BackendClient::ENDPOINT_IDME_MFA_COMPLETE => [
                'idmeCompleteMFAResponse' => [
                    'uuid' => 'session-1',
                    'status' => 'rejected',
                    'errors' => ['code' => 'invalid_code', 'base' => 'The code is incorrect'],
                ],
            ],
        ]), $store);

        $standing = $service->complete($this->credential(), 42, '000000');

        $this->assertSame('FAILED', $standing['status']);
        $this->assertFalse($standing['active']);
        $this->assertStringContainsString('The code is incorrect', $standing['message']);
        $this->assertStringContainsString('invalid_code', $standing['message']);
    }

    public function testCompleteIsReplaySafeOnceAccepted(): void
    {
        $store = $this->storeWithWaitingSession();
        $service = new Service($this->backend([
            BackendClient::ENDPOINT_IDME_MFA_COMPLETE => [
                'idmeCompleteMFAResponse' => [
                    'uuid' => 'session-1', 'status' => 'accepted', 'expires' => $this->future(),
                ],
            ],
        ], $calls), $store);

        $first = $service->complete($this->credential(), 42, '123456');
        $second = $service->complete($this->credential(), 42, '123456');
        $third = $service->checkDelivery($this->credential(), 42);

        // One backend call, one write; the replays answer from the record.
        $this->assertCount(1, $calls);
        $this->assertSame(['outcome:session-1:ACCEPTED'], $store->writes);
        $this->assertTrue($first['active']);
        $this->assertTrue($second['active']);
        $this->assertTrue($third['active']);
        $this->assertCount(1, $store->rows);
    }

    public function testCompleteRefusesAnExpiredSessionWithoutACall(): void
    {
        $store = $this->storeWithWaitingSession(
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-1 minute')->format('Y-m-d H:i:s')
        );
        $backend = $this->createMock(BackendClient::class);
        $backend->expects($this->never())->method('post');

        $this->expectException(BackendException::class);
        $this->expectExceptionMessage('expired');
        (new Service($backend, $store))->complete($this->credential(), 42, '123456');
    }

    public function testCompleteRefusesWhenNoSessionWasStarted(): void
    {
        $backend = $this->createMock(BackendClient::class);
        $backend->expects($this->never())->method('post');

        $this->expectException(BackendException::class);
        $this->expectExceptionMessage('Start MFA');
        (new Service($backend, new InMemoryIdmeMfaSessionStore()))->complete($this->credential(), 42, '123456');
    }

    public function testCompleteRefusesABlankCodeWithoutACall(): void
    {
        $backend = $this->createMock(BackendClient::class);
        $backend->expects($this->never())->method('post');

        $this->expectException(BackendException::class);
        (new Service($backend, $this->storeWithWaitingSession()))->complete($this->credential(), 42, '  ');
    }

    public function testCompleteDoesNotRecordWhenTheBackendReportsAFailure(): void
    {
        $store = $this->storeWithWaitingSession();
        $service = new Service($this->backend([
            BackendClient::ENDPOINT_IDME_MFA_COMPLETE => [
                'transactionStatus' => ['serverError' => true, 'statusMessage' => 'ID.me unavailable'],
            ],
        ]), $store);

        try {
            $service->complete($this->credential(), 42, '123456');
            $this->fail('expected a BackendException');
        } catch (BackendException $e) {
            $this->assertStringContainsString('ID.me unavailable', $e->getMessage());
        }
        $this->assertSame('WAITING', $store->find(3, 42)['status']);
        $this->assertSame([], $store->writes);
    }

    public function testCheckDeliveryPollsUntilAccepted(): void
    {
        $store = $this->storeWithWaitingSession(null, 'push');
        $answers = [
            ['idmeDeliveryCheckMFAStatusResponse' => ['uuid' => 'session-1', 'status' => 'waiting']],
            ['idmeDeliveryCheckMFAStatusResponse' => ['uuid' => 'session-1', 'status' => 'waiting']],
            ['idmeDeliveryCheckMFAStatusResponse' => [
                'uuid' => 'session-1', 'status' => 'accepted', 'expires' => $this->future(),
            ]],
        ];
        $backend = $this->createMock(BackendClient::class);
        $backend->method('post')->willReturnCallback(
            function (string $endpoint, array $payload) use (&$answers): array {
                $this->assertSame(BackendClient::ENDPOINT_IDME_MFA_DELIVERY_STATUS, $endpoint);
                $this->assertSame('session-1', $payload['idmeStartMFAResponseUuid']);
                $this->assertArrayNotHasKey('idmeVerificationCode', $payload);
                return array_shift($answers);
            }
        );
        $service = new Service($backend, $store);

        $this->assertFalse($service->checkDelivery($this->credential(), 42)['active']);
        $this->assertSame('WAITING', $service->checkDelivery($this->credential(), 42)['status']);
        $third = $service->checkDelivery($this->credential(), 42);
        $this->assertTrue($third['active']);
        $this->assertTrue($third['is_push']);
        $this->assertSame([], $answers);
    }

    public function testAnAnswerForAnotherSessionIsNotRecorded(): void
    {
        $store = $this->storeWithWaitingSession(null, 'push');
        $service = new Service($this->backend([
            BackendClient::ENDPOINT_IDME_MFA_DELIVERY_STATUS => [
                'idmeDeliveryCheckMFAStatusResponse' => [
                    'uuid' => 'some-other-session', 'status' => 'accepted', 'expires' => $this->future(),
                ],
            ],
        ]), $store);

        try {
            $service->checkDelivery($this->credential(), 42);
            $this->fail('expected a BackendException');
        } catch (BackendException $e) {
            $this->assertStringContainsString('different MFA session', $e->getMessage());
        }
        $this->assertSame('WAITING', $store->find(3, 42)['status']);
        $this->assertSame([], $store->writes);
    }

    public function testAnUnknownStatusWordNeverCountsAsAccepted(): void
    {
        foreach (['', 'approved', 'SUCCESS', 'verified', 'accepted-ish', 'ok', 'true'] as $word) {
            $outcome = Service::parseOutcome(
                ['idmeCompleteMFAResponse' => ['uuid' => 's', 'status' => $word]],
                'idmeCompleteMFAResponse'
            );
            $this->assertSame('FAILED', $outcome['status'], '"' . $word . '"');
            $this->assertNotSame('', $outcome['message'], '"' . $word . '"');
        }

        $this->assertSame('ACCEPTED', Service::parseOutcome(['k' => ['status' => 'Accepted']], 'k')['status']);
        $this->assertSame('WAITING', Service::parseOutcome(['k' => ['status' => 'waiting']], 'k')['status']);
        $this->assertSame('WAITING', Service::parseOutcome(['k' => ['status' => 'PENDING']], 'k')['status']);
        // The wrong key, or no body at all, is a failure, not a pass.
        $wrongKey = Service::parseOutcome(
            ['idmeStartMFAResponse' => ['status' => 'accepted']],
            'idmeCompleteMFAResponse'
        );
        $this->assertSame('FAILED', $wrongKey['status']);
        $this->assertSame('FAILED', Service::parseOutcome([], 'k')['status']);
    }

    public function testParseOutcomeKeepsTheBackendsErrorTextAndDropsABadTime(): void
    {
        $outcome = Service::parseOutcome([
            'idmeDeliveryCheckMFAStatusResponse' => [
                'uuid' => ' session-9 ',
                'status' => 'denied',
                'message' => 'User declined the push',
                'code' => 'denied',
                'expires' => 'not a time',
            ],
        ], 'idmeDeliveryCheckMFAStatusResponse');

        $this->assertSame('session-9', $outcome['session_uuid']);
        $this->assertSame('FAILED', $outcome['status']);
        $this->assertNull($outcome['expires_at']);
        $this->assertSame('User declined the push — denied', $outcome['message']);
    }

    public function testStandingTrustsAcceptedOnlyUntilTheExpiryTime(): void
    {
        $now = new DateTimeImmutable('2026-10-17 12:00:00', new DateTimeZone('UTC'));
        $row = ['session_uuid' => 's', 'status' => 'ACCEPTED', 'expires_at' => '2026-10-17 20:00:00'];

        $before = Service::standing($row, $now);
        $this->assertTrue($before['active']);
        $this->assertSame('ACCEPTED', $before['status']);
        $this->assertSame(8 * 3600, $before['seconds_left']);
        $this->assertSame('2026-10-17T20:00:00+00:00', $before['expires_at']);

        $utc = new DateTimeZone('UTC');
        $onTheSecond = Service::standing($row, new DateTimeImmutable('2026-10-17 20:00:00', $utc));
        $this->assertFalse($onTheSecond['active']);
        $this->assertSame('EXPIRED', $onTheSecond['status']);
        $this->assertSame(0, $onTheSecond['seconds_left']);

        $after = Service::standing($row, new DateTimeImmutable('2026-10-18 09:00:00', $utc));
        $this->assertSame('EXPIRED', $after['status']);
        $this->assertFalse($after['active']);
    }

    public function testStandingNeverActivatesWithoutAnExpiryOrOutsideAccepted(): void
    {
        $this->assertSame('NONE', Service::standing(null)['status']);
        $this->assertFalse(Service::standing(null)['active']);

        $noExpiry = Service::standing(['session_uuid' => 's', 'status' => 'ACCEPTED', 'expires_at' => null]);
        $this->assertSame('EXPIRED', $noExpiry['status']);
        $this->assertFalse($noExpiry['active']);

        $future = $this->futureStored();
        foreach (['WAITING', 'FAILED'] as $status) {
            $row = ['session_uuid' => 's', 'status' => $status, 'expires_at' => $future];
            $this->assertFalse(Service::standing($row)['active'], $status);
        }
        // A word that is not one of ours in the row — a hand edit, say — is FAILED.
        $odd = Service::standing(['session_uuid' => 's', 'status' => 'approved', 'expires_at' => $future]);
        $this->assertSame('FAILED', $odd['status']);
        $this->assertFalse($odd['active']);
        // No session uuid means nothing to complete against.
        $this->assertSame('NONE', Service::standing(['status' => 'ACCEPTED', 'expires_at' => $future])['status']);
    }

    public function testControlledSendDecisionNeedsAllThree(): void
    {
        $now = new DateTimeImmutable('2026-10-17 12:00:00', new DateTimeZone('UTC'));
        $verified = ['verification_status' => 'VERIFIED', 'verification_exp_date' => '2027-01-01'];
        $lapsedVerification = ['verification_status' => 'VERIFIED', 'verification_exp_date' => '2026-01-01'];
        $activeMfa = ['session_uuid' => 's', 'status' => 'ACCEPTED', 'expires_at' => '2026-10-17 20:00:00'];
        $expiredMfa = ['session_uuid' => 's', 'status' => 'ACCEPTED', 'expires_at' => '2026-10-17 08:00:00'];
        $waitingMfa = ['session_uuid' => 's', 'status' => 'WAITING', 'expires_at' => '2026-10-17 20:00:00'];

        $this->assertSame('not_epcs', Service::controlledSendDecision(false, $verified, $activeMfa, $now)['reason']);
        $this->assertSame('not_verified', Service::controlledSendDecision(true, null, $activeMfa, $now)['reason']);
        $this->assertSame(
            'not_verified',
            Service::controlledSendDecision(true, $lapsedVerification, $activeMfa, $now)['reason']
        );
        $this->assertSame('mfa_required', Service::controlledSendDecision(true, $verified, null, $now)['reason']);
        $this->assertSame(
            'mfa_required',
            Service::controlledSendDecision(true, $verified, $waitingMfa, $now)['reason']
        );
        $this->assertSame('mfa_expired', Service::controlledSendDecision(true, $verified, $expiredMfa, $now)['reason']);

        $allowed = Service::controlledSendDecision(true, $verified, $activeMfa, $now);
        $this->assertTrue($allowed['allowed']);
        $this->assertNull($allowed['reason']);
        $this->assertSame(8 * 3600, $allowed['mfa']['seconds_left']);

        foreach (
            [
                Service::controlledSendDecision(false, $verified, $activeMfa, $now),
                Service::controlledSendDecision(true, null, $activeMfa, $now),
                Service::controlledSendDecision(true, $verified, $expiredMfa, $now),
            ] as $refused
        ) {
            $this->assertFalse($refused['allowed']);
        }
    }

    public function testIsPushMethodReadsOptionOrName(): void
    {
        $this->assertTrue(Service::isPushMethod(['name' => 'Push Notification', 'option' => '']));
        $this->assertTrue(Service::isPushMethod(['name' => 'Mobile', 'option' => 'push']));
        $this->assertFalse(Service::isPushMethod(['name' => 'Authenticator', 'option' => 'generator']));
        $this->assertFalse(Service::isPushMethod([]));
    }
}
