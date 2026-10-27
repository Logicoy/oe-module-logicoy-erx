<?php

/**
 * Authentication, routing and replay tests for pushed notifications.
 *
 * The backend can redeliver a push, and the same notification can also arrive
 * by poll, so a repeat must record nothing new and call no follow-up twice.
 * The real NotificationPoller is used with a mocked backend; all data here is
 * synthetic.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Client\BackendClient;
use LogiCoy\ERx\Client\BackendException;
use LogiCoy\ERx\Config\CredentialStore;
use LogiCoy\ERx\Config\GlobalConfig;
use LogiCoy\ERx\Prescription\NotificationStore;
use LogiCoy\ERx\Queue\NotificationPoller;
use LogiCoy\ERx\Webhook\WebhookReceiver;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\TestCase;

class WebhookReceiverTest extends TestCase
{
    private const SECRET = 'test-webhook-secret-0001';
    private const REGISTRATION = 'reg-test-0001';

    /** @var array<int, array<string, mixed>> */
    private array $facilities = [
        [
            'id' => 1,
            'facility_id' => 3,
            'erx_client_registration_uuid' => self::REGISTRATION,
            'erx_practice_uuid' => 'practice-test-0001',
            'erx_clinician_user_pk_uuid' => 'clinician-test-0001',
        ],
    ];

    /** @var array<int, string> */
    private array $calls = [];

    /** @var array<string, array<string, mixed>|BackendException> */
    private array $followUps = [];

    /** @var array<string, mixed> */
    private array $globals = [];

    private InMemoryNotificationStore $store;
    private InMemoryPrescriptionTracker $tracker;

    protected function setUp(): void
    {
        $this->calls = [];
        $this->followUps = [];
        $this->globals = [
            GlobalConfig::ENABLE_MODULE => '1',
            GlobalConfig::WEBHOOK_ENABLED => '1',
            GlobalConfig::QUEUE_MAX_ATTEMPTS => 3,
        ];
        $this->store = new InMemoryNotificationStore();
        $this->tracker = new InMemoryPrescriptionTracker();
    }

    private function receiver(string $secret = self::SECRET): WebhookReceiver
    {
        $backend = $this->createMock(BackendClient::class);
        $backend->method('post')->willReturnCallback(function (string $endpoint): array {
            $this->calls[] = $endpoint;
            $answer = $this->followUps[$endpoint] ?? [];
            if ($answer instanceof BackendException) {
                throw $answer;
            }
            return $answer;
        });

        $credentials = $this->createMock(CredentialStore::class);
        $credentials->method('getPracticeCredentialsForRegistration')->willReturnCallback(
            fn(string $registration): array => array_values(array_filter(
                $this->facilities,
                static fn($f) => $f['erx_client_registration_uuid'] === $registration
            ))
        );

        $config = new GlobalConfig($this->globals);
        $poller = new NotificationPoller(
            $config,
            $backend,
            $this->store,
            $this->tracker,
            $this->createMock(SystemLogger::class),
            $this->createMock(EventAuditLogger::class),
            'logicoy-erx-webhook'
        );

        return new WebhookReceiver($config, $secret, $credentials, $poller, $this->createMock(SystemLogger::class));
    }

    /**
     * @param array<int, array<string, mixed>> $notifications
     */
    private function push(array $notifications, array $envelope = []): string
    {
        return json_encode($envelope + [
            'transactionUuid' => 'tx-test-0001',
            'clientRegistrationPkUuid' => self::REGISTRATION,
            'pharmacyInitiatedTxNotificationList' => $notifications,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function notification(string $uuid, string $type, array $extra = []): array
    {
        return $extra + [
            'erxNotificationPkUuid' => $uuid,
            'rxTypeName' => $type,
            'patientPKUuid' => 'erx-patient-test-0001',
            'patientFullName' => 'Test Patient',
            'clinicianPKUuid' => 'clinician-test-0001',
            'practicePkUuid' => 'practice-test-0001',
            'notificationMessage' => 'Synthetic notification for test use only',
            'isReplyRequired' => true,
        ];
    }

    public function testDisabledWebhookLooksLikeAMissingPage(): void
    {
        $this->globals[GlobalConfig::WEBHOOK_ENABLED] = '0';

        $result = $this->receiver()->handle(self::SECRET, $this->push([]));

        $this->assertSame(404, $result['status']);
    }

    public function testDisabledModuleRefusesEvenWithTheWebhookOn(): void
    {
        $this->globals[GlobalConfig::ENABLE_MODULE] = '0';

        $this->assertSame(404, $this->receiver()->handle(self::SECRET, $this->push([]))['status']);
    }

    public function testEnabledWithoutASecretFailsClosed(): void
    {
        $result = $this->receiver('')->handle('', $this->push([]));

        $this->assertSame(503, $result['status']);
        $this->assertSame([], $this->store->rows);
    }

    public function testMissingOrWrongSecretIsRejectedBeforeTheBodyIsRead(): void
    {
        $push = $this->push([$this->notification('n-1', 'RX_RENEWAL_REQUEST')]);

        $this->assertSame(401, $this->receiver()->handle('', $push)['status']);
        $this->assertSame(401, $this->receiver()->handle('wrong-secret', $push)['status']);
        $this->assertSame([], $this->store->rows);
    }

    public function testMalformedBodiesAreBadRequests(): void
    {
        $receiver = $this->receiver();

        $this->assertSame(400, $receiver->handle(self::SECRET, 'not json')['status']);
        $this->assertSame(400, $receiver->handle(self::SECRET, '"a string"')['status']);
        $this->assertSame(400, $receiver->handle(self::SECRET, json_encode([
            'transactionUuid' => 'tx-test-0001',
            'pharmacyInitiatedTxNotificationList' => [],
        ]))['status']);
    }

    public function testOversizedBodyIsRefused(): void
    {
        $body = str_repeat(' ', WebhookReceiver::MAX_BODY_BYTES + 1);

        $this->assertSame(413, $this->receiver()->handle(self::SECRET, $body)['status']);
    }

    public function testUnknownRegistrationIsRefusedAndRecordsNothing(): void
    {
        $push = $this->push(
            [$this->notification('n-1', 'RX_RENEWAL_REQUEST')],
            ['clientRegistrationPkUuid' => 'reg-someone-else']
        );

        $result = $this->receiver()->handle(self::SECRET, $push);

        $this->assertSame(400, $result['status']);
        $this->assertSame([], $this->store->rows);
    }

    public function testRenewalRequestIsOpenedForThePrescriberWithoutCallingTheBackend(): void
    {
        $result = $this->receiver()->handle(self::SECRET, $this->push([
            $this->notification('n-renew-1', 'RX_RENEWAL_REQUEST'),
        ]));

        $this->assertSame(200, $result['status']);
        $this->assertSame('tx-test-0001', $result['body']['transactionUuid']);
        $this->assertSame('OK', $result['body']['transactionStatus']['status']);
        $this->assertSame(1, $result['body']['summary']['processed']);
        $this->assertSame(NotificationStore::STATE_OPEN, $this->store->rows['n-renew-1']['state']);
        $this->assertSame(3, $this->store->rows['n-renew-1']['facility_id']);
        $this->assertSame([], $this->calls);
    }

    public function testRedeliveredPushRecordsOnceAndFollowsUpOnce(): void
    {
        $this->followUps[BackendClient::ENDPOINT_RXCANCEL_STATUS] = ['rxCancelResponseStatus' => 'Approved'];
        $push = $this->push([
            $this->notification('n-cancel-1', 'RX_CANCEL_RESPONSE', [
                'erxEprescriptionMedicationPKUuid' => 'med-test-0001',
            ]),
            $this->notification('n-renew-1', 'RX_RENEWAL_REQUEST'),
        ]);

        $receiver = $this->receiver();
        $first = $receiver->handle(self::SECRET, $push);
        $second = $receiver->handle(self::SECRET, $push);
        $third = $receiver->handle(self::SECRET, $push);

        $this->assertSame(2, $first['body']['summary']['processed']);
        $this->assertSame(2, $second['body']['summary']['replayed']);
        $this->assertSame(2, $third['body']['summary']['replayed']);
        $this->assertSame(200, $third['status']);
        $this->assertCount(2, $this->store->rows);
        $this->assertSame([BackendClient::ENDPOINT_RXCANCEL_STATUS], $this->calls);
    }

    public function testAFailedFollowUpAsksForRedeliveryAndTheRetrySucceeds(): void
    {
        $this->followUps[BackendClient::ENDPOINT_RXFILL_STATUS] = new BackendException('Backend unavailable', 503);
        $push = $this->push([
            $this->notification('n-fill-1', 'RX_FILL_STATUS', ['erxEprescriptionMedicationPKUuid' => 'med-test-0001']),
        ]);

        $failed = $this->receiver()->handle(self::SECRET, $push);

        $this->assertSame(500, $failed['status']);
        $this->assertTrue($failed['body']['transactionStatus']['serverError']);
        $this->assertNull($this->store->rows['n-fill-1']['processed_at']);

        $this->followUps[BackendClient::ENDPOINT_RXFILL_STATUS] = ['rxFillStatus' => 'FILLED'];
        $retried = $this->receiver()->handle(self::SECRET, $push);

        $this->assertSame(200, $retried['status']);
        $this->assertSame(1, $retried['body']['summary']['processed']);
        $this->assertNotNull($this->store->rows['n-fill-1']['processed_at']);
    }

    public function testAPushAlreadySeenByPollIsAReplay(): void
    {
        $notification = $this->notification('n-renew-1', 'RX_RENEWAL_REQUEST');
        $receiver = $this->receiver();

        // As if the cron poll got there first.
        $this->store->record([
            'uuid' => 'n-renew-1',
            'facility_id' => 3,
            'pid' => null,
            'message' => '',
            'state' => NotificationStore::STATE_RESPONDED,
        ]);
        $this->store->markProcessed('n-renew-1');

        $result = $receiver->handle(self::SECRET, $this->push([$notification]));

        $this->assertSame(1, $result['body']['summary']['replayed']);
        // An answered request is not reopened by the push.
        $this->assertSame(NotificationStore::STATE_RESPONDED, $this->store->rows['n-renew-1']['state']);
    }

    public function testNotificationsAreRoutedToTheFacilityOfTheirPractice(): void
    {
        $this->facilities[] = [
            'id' => 2,
            'facility_id' => 7,
            'erx_client_registration_uuid' => self::REGISTRATION,
            'erx_practice_uuid' => 'practice-test-0002',
        ];

        $result = $this->receiver()->handle(self::SECRET, $this->push([
            $this->notification('n-a', 'RX_RENEWAL_REQUEST'),
            $this->notification('n-b', 'RX_CHANGE_REQUEST', ['practicePkUuid' => 'practice-test-0002']),
            $this->notification('n-c', 'RX_CHANGE_REQUEST', ['practicePkUuid' => 'practice-not-here']),
        ]));

        $this->assertSame(200, $result['status']);
        $this->assertSame(3, $this->store->rows['n-a']['facility_id']);
        $this->assertSame(7, $this->store->rows['n-b']['facility_id']);
        $this->assertArrayNotHasKey('n-c', $this->store->rows);
        $this->assertSame(1, $result['body']['summary']['skipped']);
    }

    public function testEnvelopePracticeRoutesWhenTheItemNamesNone(): void
    {
        $this->facilities[] = [
            'id' => 2,
            'facility_id' => 7,
            'erx_client_registration_uuid' => self::REGISTRATION,
            'erx_practice_uuid' => 'practice-test-0002',
        ];

        $this->receiver()->handle(self::SECRET, $this->push(
            [$this->notification('n-a', 'RX_RENEWAL_REQUEST', ['practicePkUuid' => ''])],
            ['erxPracticePKUuid' => 'practice-test-0002']
        ));

        $this->assertSame(7, $this->store->rows['n-a']['facility_id']);
    }

    public function testEventHintFillsInOnlyAMissingType(): void
    {
        $receiver = $this->receiver();

        $receiver->handle(
            self::SECRET,
            $this->push([
                $this->notification('n-untyped', '', ['rxTypeName' => '']),
                $this->notification('n-typed', 'RX_RENEWAL_REQUEST'),
            ]),
            'change-request'
        );

        $this->assertSame('RX_CHANGE_REQUEST', $this->store->rows['n-untyped']['rx_type']);
        $this->assertSame('RX_RENEWAL_REQUEST', $this->store->rows['n-typed']['rx_type']);
    }

    public function testHistoryResponseIsRecordedForTheLog(): void
    {
        $result = $this->receiver()->handle(self::SECRET, $this->push([
            $this->notification('n-hist-1', 'RX_HISTORY_RESPONSE'),
        ]));

        $this->assertSame(200, $result['status']);
        $this->assertSame(NotificationStore::STATE_INFO, $this->store->rows['n-hist-1']['state']);
        $this->assertSame([], $this->calls);
    }

    public function testSecretIsReadFromBearerOrTheDedicatedHeader(): void
    {
        $this->assertSame('abc', WebhookReceiver::presentedSecret(['HTTP_AUTHORIZATION' => 'Bearer abc']));
        $this->assertSame('abc', WebhookReceiver::presentedSecret(['REDIRECT_HTTP_AUTHORIZATION' => 'bearer abc ']));
        $this->assertSame('abc', WebhookReceiver::presentedSecret(['HTTP_X_LOGICOY_WEBHOOK_SECRET' => 'abc']));
        $this->assertSame('abc', WebhookReceiver::presentedSecret([], ['authorization' => 'Bearer abc']));
        $this->assertSame('abc', WebhookReceiver::presentedSecret([], ['x-logicoy-webhook-secret' => 'abc']));
        $this->assertSame('', WebhookReceiver::presentedSecret(['HTTP_AUTHORIZATION' => 'Basic abc']));
        $this->assertSame('', WebhookReceiver::presentedSecret([]));
    }


    public function testEventIsReadFromThePathTheBackendPostsTo(): void
    {
        $this->assertSame(
            'pharmacy-initiated-new-rx-request',
            WebhookReceiver::eventFromPath('/rx/pharmacy-initiated-new-rx-request')
        );
        $this->assertSame('renewal-request', WebhookReceiver::eventFromPath('rx/Renewal-Request/'));
        $this->assertSame('', WebhookReceiver::eventFromPath(''));
        $this->assertSame('', WebhookReceiver::eventFromPath('/'));
        $this->assertSame('', WebhookReceiver::eventFromPath('/rx/..'));
    }

    public function testPharmacyInitiatedNewRxPushIsOpenedForThePrescriber(): void
    {
        $this->followUps[BackendClient::ENDPOINT_PHARMACY_NEW_RX_GET] = [
            'rxNewRequestFromPharmacy' => [
                'patient' => ['firstName' => 'Test', 'lastName' => 'Patient', 'dateOfBirth' => '1980-01-01'],
                'medicationRequested' => ['drugDescription' => 'TEST-DRUG 10 MG tablet'],
            ],
            'isNewPatient' => true,
            'erxPatientPKUuid' => 'erx-patient-new-0001',
        ];

        // As staging sends it: no type on the item, no patient, the event in the path.
        $result = $this->receiver()->handle(
            self::SECRET,
            $this->push([
                $this->notification('n-newrx-1', '', [
                    'rxTypeName' => '',
                    'patientPKUuid' => '',
                    'patientFullName' => '',
                ]),
            ]),
            WebhookReceiver::eventFromPath('/rx/pharmacy-initiated-new-rx-request')
        );

        $row = $this->store->rows['n-newrx-1'];
        $this->assertSame(200, $result['status']);
        $this->assertSame('NEW_RX_REQUEST', $row['rx_type']);
        $this->assertSame(NotificationStore::STATE_OPEN, $row['state']);
        $this->assertNull($row['pid']);
        $this->assertSame('Test Patient', $row['patient_name']);
        $this->assertSame([BackendClient::ENDPOINT_PHARMACY_NEW_RX_GET], $this->calls);
    }
}
