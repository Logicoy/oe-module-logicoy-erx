<?php

/**
 * Replay and routing tests for the inbound notification poll.
 *
 * /v1/notifications/get answers with every notification on every call, and
 * OpenEMR's cron replays, so a repeated poll must record nothing new, call no
 * follow-up twice, and never reopen a request already answered. All data here
 * is synthetic.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Client\BackendClient;
use LogiCoy\ERx\Client\BackendException;
use LogiCoy\ERx\Config\GlobalConfig;
use LogiCoy\ERx\Prescription\NotificationStore;
use LogiCoy\ERx\Queue\NotificationPoller;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\TestCase;

class NotificationPollerTest extends TestCase
{
    private const FACILITY = 3;

    /** @var array<string, mixed> */
    private array $credential = [
        'id' => 1,
        'erx_client_registration_uuid' => 'reg-test-0001',
        'erx_practice_uuid' => 'practice-test-0001',
        'erx_location_uuid' => 'location-test-0001',
        'erx_clinician_user_pk_uuid' => 'clinician-test-0001',
    ];

    /** @var array<int, array{endpoint: string, payload: array<string, mixed>}> */
    private array $calls = [];

    private InMemoryNotificationStore $store;
    private InMemoryPrescriptionTracker $tracker;

    protected function setUp(): void
    {
        $this->calls = [];
        $this->store = new InMemoryNotificationStore();
        $this->tracker = new InMemoryPrescriptionTracker();
    }

    /**
     * @param array<int, array<string, mixed>> $notifications
     * @param array<string, array<string, mixed>|BackendException> $followUps endpoint => response
     */
    private function poller(array $notifications, array $followUps = [], int $maxAttempts = 3): NotificationPoller
    {
        $backend = $this->createMock(BackendClient::class);
        $backend->method('post')->willReturnCallback(
            function (string $endpoint, array $payload) use ($notifications, $followUps): array {
                $this->calls[] = ['endpoint' => $endpoint, 'payload' => $payload];

                if ($endpoint === BackendClient::ENDPOINT_NOTIFICATIONS) {
                    return ['pharmacyInitiatedTxNotificationList' => $notifications];
                }
                $answer = $followUps[$endpoint] ?? [];
                if ($answer instanceof BackendException) {
                    throw $answer;
                }
                return $answer;
            }
        );

        return new NotificationPoller(
            new GlobalConfig([GlobalConfig::QUEUE_MAX_ATTEMPTS => $maxAttempts]),
            $backend,
            $this->store,
            $this->tracker,
            $this->createMock(SystemLogger::class),
            $this->createMock(EventAuditLogger::class)
        );
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
            'priorityType' => 'HIGH',
            'requestedDate' => '2026-01-07T10:15:30Z',
            'isReplyRequired' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cancelResponse(): array
    {
        return $this->notification('n-cancel-1', 'RX_CANCEL_RESPONSE', [
            'erxEprescriptionMedicationPKUuid' => 'med-test-0001',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function followUpEndpoints(): array
    {
        return array_values(array_filter(
            array_column($this->calls, 'endpoint'),
            static fn($e) => $e !== BackendClient::ENDPOINT_NOTIFICATIONS
        ));
    }

    public function testPollAsksForThisInstallsRegistration(): void
    {
        $this->poller([])->poll($this->credential, self::FACILITY);

        $this->assertSame(BackendClient::ENDPOINT_NOTIFICATIONS, $this->calls[0]['endpoint']);
        $this->assertSame('reg-test-0001', $this->calls[0]['payload']['clientRegistrationPkUuid']);
        $this->assertSame(0, $this->calls[0]['payload']['pagination']['pageNumber']);
    }

    public function testPollWithoutARegistrationUuidFailsLoudly(): void
    {
        $this->expectException(BackendException::class);
        $this->poller([])->poll(['erx_client_registration_uuid' => ''] + $this->credential, self::FACILITY);
    }

    public function testRenewalRequestIsOpenedForThePrescriberWithoutCallingAnything(): void
    {
        $this->store->patients['erx-patient-test-0001'] = 4242;

        $counts = $this->poller([$this->notification('n-renew-1', 'RX_RENEWAL_REQUEST')])
            ->poll($this->credential, self::FACILITY);

        $row = $this->store->rows['n-renew-1'];
        $this->assertSame(NotificationStore::STATE_OPEN, $row['state']);
        $this->assertSame(4242, $row['pid']);
        $this->assertTrue($row['reply_required']);
        $this->assertNotNull($row['processed_at']);
        $this->assertSame([], $this->followUpEndpoints(), 'nothing is sent until the prescriber answers');
        $this->assertSame(1, $counts['processed']);
    }

    public function testReplayedPollDoesNotReopenOrReprocessAnything(): void
    {
        $this->tracker->add('med-test-0001', 4242);
        $notifications = [
            $this->notification('n-renew-1', 'RX_RENEWAL_REQUEST'),
            $this->notification('n-cancel-1', 'RX_CANCEL_RESPONSE', [
                'erxEprescriptionMedicationPKUuid' => 'med-test-0001',
            ]),
        ];
        $followUps = [BackendClient::ENDPOINT_RXCANCEL_STATUS => ['rxCancelResponseStatus' => 'CANCELLED']];

        $first = $this->poller($notifications, $followUps)->poll($this->credential, self::FACILITY);

        // The prescriber answers the renewal between polls.
        $this->store->rows['n-renew-1']['state'] = NotificationStore::STATE_RESPONDED;

        $second = $this->poller($notifications, $followUps)->poll($this->credential, self::FACILITY);
        $third = $this->poller($notifications, $followUps)->poll($this->credential, self::FACILITY);

        $this->assertSame(2, $first['processed']);
        $this->assertSame(2, $second['replayed']);
        $this->assertSame(2, $third['replayed']);
        $this->assertSame(0, $second['processed'] + $third['processed']);

        $this->assertSame(
            [BackendClient::ENDPOINT_RXCANCEL_STATUS],
            $this->followUpEndpoints(),
            'the cancel status is looked up once across three polls'
        );
        $this->assertSame(
            NotificationStore::STATE_RESPONDED,
            $this->store->rows['n-renew-1']['state'],
            'a replay must not reopen an answered request'
        );
        $this->assertCount(2, $this->store->rows);
    }

    public function testCancelResponseIsAppliedToTheSentPrescription(): void
    {
        $this->tracker->add('med-test-0001', 4242);

        $this->poller(
            [$this->cancelResponse()],
            [BackendClient::ENDPOINT_RXCANCEL_STATUS => [
                'rxCancelResponseStatus' => 'DENIED',
                'rxCancelResponseReason' => 'Already dispensed',
            ]]
        )->poll($this->credential, self::FACILITY);

        $this->assertSame('cancel_denied', $this->tracker->rows['med-test-0001']['status']);
        $this->assertSame(4242, $this->store->rows['n-cancel-1']['pid'], 'the Rx names the patient');
        $this->assertSame('n-cancel-1', $this->calls[1]['payload']['erxNotificationPKUuid']);
        $this->assertSame('clinician-test-0001', $this->calls[1]['payload']['erxClinicianUserPKUuid']);
    }

    public function testFillAndVerificationResultsUpdateThePrescription(): void
    {
        $this->tracker->add('med-test-0001', 4242);
        $med = ['erxEprescriptionMedicationPKUuid' => 'med-test-0001'];

        $this->poller(
            [
                $this->notification('n-fill-1', 'RX_FILL', $med),
                $this->notification('n-new-1', 'RX_NEW', $med),
            ],
            [
                BackendClient::ENDPOINT_RXFILL_STATUS => ['rxFillStatus' => 'DISPENSED'],
                BackendClient::ENDPOINT_RXNEW_VERIFICATION_STATUS => ['verificationStatus' => 'SUCCESS'],
            ]
        )->poll($this->credential, self::FACILITY);

        $this->assertSame('DISPENSED', $this->tracker->rows['med-test-0001']['fill_status']);
        $this->assertSame('SUCCESS', $this->tracker->rows['med-test-0001']['transmission_status']);
        $this->assertSame(NotificationStore::STATE_INFO, $this->store->rows['n-fill-1']['state']);
    }

    public function testAnotherPracticesNotificationIsLeftAlone(): void
    {
        $counts = $this->poller([
            $this->notification('n-other-1', 'RX_RENEWAL_REQUEST', ['practicePkUuid' => 'practice-someone-else']),
        ])->poll($this->credential, self::FACILITY);

        $this->assertSame(1, $counts['skipped']);
        $this->assertSame([], $this->store->rows);
    }

    public function testNotificationWithoutAnIdIsSkipped(): void
    {
        $counts = $this->poller([$this->notification('', 'RX_RENEWAL_REQUEST')])
            ->poll($this->credential, self::FACILITY);

        $this->assertSame(1, $counts['skipped']);
        $this->assertSame([], $this->store->rows);
    }

    public function testFailedFollowUpIsRetriedThenGivenUp(): void
    {
        $this->tracker->add('med-test-0001', 4242);
        $notifications = [
            $this->notification('n-fill-1', 'RX_FILL', ['erxEprescriptionMedicationPKUuid' => 'med-test-0001']),
        ];
        $outage = [BackendClient::ENDPOINT_RXFILL_STATUS => new BackendException('backend down', 503)];

        $first = $this->poller($notifications, $outage, 2)->poll($this->credential, self::FACILITY);
        $this->assertSame(1, $first['failed']);
        $this->assertNull($this->store->rows['n-fill-1']['processed_at'], 'left for the next poll');

        $this->poller($notifications, $outage, 2)->poll($this->credential, self::FACILITY);
        $this->assertNotNull($this->store->rows['n-fill-1']['processed_at'], 'given up at the attempt limit');

        $third = $this->poller($notifications, $outage, 2)->poll($this->credential, self::FACILITY);
        $this->assertSame(1, $third['replayed']);
        $this->assertCount(2, $this->followUpEndpoints(), 'no lookup after giving up');
    }

    public function testFailureReportedInsideA200IsNotTakenAsAnAnswer(): void
    {
        $this->tracker->add('med-test-0001', 4242);

        $counts = $this->poller(
            [$this->cancelResponse()],
            [BackendClient::ENDPOINT_RXCANCEL_STATUS => [
                'isValidationError' => true,
                'validationErrorInfo' => [['errorMessage' => 'Notification not found']],
            ]]
        )->poll($this->credential, self::FACILITY);

        $this->assertSame(1, $counts['failed']);
        $this->assertSame('sent', $this->tracker->rows['med-test-0001']['status']);
        $this->assertSame('Notification not found', $this->store->rows['n-cancel-1']['last_error']);
    }

    public function testDatesAreConvertedToLocalDatetime(): void
    {
        $this->assertNull(NotificationPoller::toDateTime(''));
        $this->assertNull(NotificationPoller::toDateTime('not a date'));
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            (string)NotificationPoller::toDateTime('2026-01-07T10:15:30Z')
        );
    }


    /**
     * /v1/pharmacy-initiated/new-rx/get-details, as staging documents it.
     *
     * @return array<string, mixed>
     */
    private function newRxDetails(string $erxPatientUuid = 'erx-patient-test-0001'): array
    {
        return [
            'rxNewRequestFromPharmacy' => [
                'patient' => ['firstName' => 'Test', 'lastName' => 'Patient', 'dateOfBirth' => '1980-01-01'],
                'pharmacy' => ['businessName' => 'Test Pharmacy'],
                'medicationRequested' => [
                    'drugDescription' => 'TEST-DRUG 10 MG tablet',
                    'quantity' => ['value' => '30'],
                    'sig' => ['sigText' => 'Synthetic directions for test use only'],
                ],
            ],
            'isNewPatient' => true,
            'erxPatientPKUuid' => $erxPatientUuid,
            'sourcePatientRecordId' => 'source-test-0001',
        ];
    }

    /**
     * The push names no patient: the backend creates them while answering
     * get-details.
     *
     * @return array<string, mixed>
     */
    private function newRxRequest(): array
    {
        return $this->notification('n-newrx-1', 'NEW_RX_REQUEST', [
            'patientPKUuid' => '',
            'patientFullName' => '',
            'notificationMessage' => 'Rx received for New Rx Request From Pharmacy',
        ]);
    }

    public function testNewRxRequestIsOpenedWithItsDetailsAndMatchedToASyncedPatient(): void
    {
        $this->store->patients['erx-patient-test-0001'] = 4242;
        $followUps = [BackendClient::ENDPOINT_PHARMACY_NEW_RX_GET => $this->newRxDetails()];

        $counts = $this->poller([$this->newRxRequest()], $followUps)->poll($this->credential, self::FACILITY);

        $row = $this->store->rows['n-newrx-1'];
        $this->assertSame(1, $counts['processed'], (string)$row['last_error']);
        $this->assertSame(NotificationStore::STATE_OPEN, $row['state'], 'the prescriber has to answer it');
        $this->assertTrue($row['reply_required']);
        $this->assertSame(4242, $row['pid'], 'matched through the patient map');
        $this->assertSame('erx-patient-test-0001', $row['patient_uuid']);
        $this->assertSame('Test Patient', $row['patient_name'], 'the push named nobody; the details do');
        $this->assertSame('new-rx', $row['request_detail']['type']);
        $this->assertSame('TEST-DRUG 10 MG tablet', $row['request_detail']['requested'][0]['drugDescription']);
        $this->assertSame('Patient', $row['request_detail']['patient']['lastName'], 'kept for creating a chart');
        $this->assertNotNull($row['processed_at']);

        $this->assertSame([BackendClient::ENDPOINT_PHARMACY_NEW_RX_GET], $this->followUpEndpoints());
        $this->assertSame('n-newrx-1', $this->calls[1]['payload']['erxNotificationPkUuid']);
        $this->assertSame('clinician-test-0001', $this->calls[1]['payload']['erxClinicianUserPKUuid']);

        // Pushed again, or polled: nothing is fetched twice.
        $again = $this->poller([$this->newRxRequest()], $followUps)->poll($this->credential, self::FACILITY);
        $this->assertSame(1, $again['replayed']);
        $this->assertCount(1, $this->followUpEndpoints());
    }

    public function testNewRxRequestForAPatientWithNoChartStaysUnlinkedButOpen(): void
    {
        $this->poller(
            [$this->newRxRequest()],
            [BackendClient::ENDPOINT_PHARMACY_NEW_RX_GET => $this->newRxDetails('erx-patient-unknown')]
        )->poll($this->credential, self::FACILITY);

        $row = $this->store->rows['n-newrx-1'];
        $this->assertSame(NotificationStore::STATE_OPEN, $row['state']);
        $this->assertNull($row['pid'], 'never matched on a name alone; the inbox offers to add or link');
        $this->assertSame('Test Patient', $row['patient_name']);
        $this->assertSame('erx-patient-unknown', $row['patient_uuid']);
    }

    public function testNewRxRequestWhoseDetailsCannotBeFetchedIsRetried(): void
    {
        $outage = [BackendClient::ENDPOINT_PHARMACY_NEW_RX_GET => new BackendException('backend down', 503)];

        $counts = $this->poller([$this->newRxRequest()], $outage)->poll($this->credential, self::FACILITY);

        $row = $this->store->rows['n-newrx-1'];
        $this->assertSame(1, $counts['failed']);
        $this->assertSame(NotificationStore::STATE_OPEN, $row['state'], 'still the prescriber\'s to answer');
        $this->assertNull($row['processed_at'], 'left for the next poll');
        $this->assertArrayNotHasKey('request_detail', $row);
    }
}
