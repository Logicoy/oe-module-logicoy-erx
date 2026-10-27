<?php

/**
 * What the worker records after a pharmacy-bound transmission, so the
 * prescriber sees whether a cancel or a response actually went. All data here
 * is synthetic.
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
use LogiCoy\ERx\Prescription\PharmacyRequestMapper;
use LogiCoy\ERx\Prescription\PrescriptionGatewayInterface;
use LogiCoy\ERx\Prescription\PrescriptionTracker;
use LogiCoy\ERx\Prescription\PrescriptionWriter;
use LogiCoy\ERx\Queue\QueueRepository;
use LogiCoy\ERx\Queue\QueueWorker;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class QueueWorkerTest extends TestCase
{
    private QueueRepository&MockObject $queue;
    private BackendClient&MockObject $backend;
    private PrescriptionTracker&MockObject $tracker;
    private NotificationStore&MockObject $notifications;
    private PrescriptionGatewayInterface&MockObject $gateway;
    /** @var array<int, array<string, mixed>> rows handed to the prescriptions gateway */
    private array $written = [];

    protected function setUp(): void
    {
        $this->queue = $this->createMock(QueueRepository::class);
        $this->backend = $this->createMock(BackendClient::class);
        $this->tracker = $this->createMock(PrescriptionTracker::class);
        $this->notifications = $this->createMock(NotificationStore::class);
        $this->gateway = $this->createMock(PrescriptionGatewayInterface::class);
        $this->gateway->method('insert')->willReturnCallback(function (array $data): int {
            $this->written[] = $data;
            return 9000 + count($this->written);
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $globals
     */
    private function worker(
        string $messageType,
        array $payload,
        ?int $patientId = 4242,
        array $globals = []
    ): QueueWorker {
        $this->queue->method('claimBatch')->willReturn([[
            'id' => 11,
            'message_type' => $messageType,
            'logicoy_message_id' => $messageType . ':' . ($payload['erxNotificationPkUuid'] ?? 'none'),
            'facility_id' => 3,
            'provider_user_id' => 5,
            'patient_id' => $patientId,
        ]]);
        $this->queue->method('getPayload')->willReturn($payload);

        $credentials = $this->createMock(CredentialStore::class);
        $credentials->method('getCredential')->willReturn(['id' => 1]);

        return new QueueWorker(
            new GlobalConfig([GlobalConfig::QUEUE_MAX_ATTEMPTS => 3] + $globals),
            $this->queue,
            $this->backend,
            $credentials,
            null,
            $this->tracker,
            $this->notifications,
            $this->createMock(SystemLogger::class),
            $this->createMock(EventAuditLogger::class),
            new PrescriptionWriter($this->gateway, new InMemoryMessageMap(), $this->createMock(EventAuditLogger::class))
        );
    }

    /** @return array<string, mixed> what the prescriber reviewed, as api.php caches it */
    private function renewalCache(): array
    {
        return PharmacyRequestMapper::detailCache(PharmacyRequestMapper::TYPE_RENEWAL, [
            'rxRenewalRequestFromPharmacy' => [
                'medicationPrescribed' => [
                    'drugDescription' => 'TEST-DRUG 10 MG tablet',
                    'drugCoded' => ['productCode' => ['code' => '00000000000', 'qualifier' => 'ND']],
                    'quantity' => ['value' => '30', 'codeListQualifier' => '38'],
                    'sig' => ['sigText' => 'Synthetic directions for test use only'],
                    'numberOfRefills' => 0,
                    'pharmacyRequestedRefills' => 2,
                ],
                'acceptedResponseCodes' => ['APPROVED', 'DENIED'],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function renewalPayload(string $decision): array
    {
        return [
            'erxNotificationPkUuid' => 'n-renew-1',
            'rxRenewalResponseMedication' => ['rxRenewalResponseCodeTypeRef' => $decision],
        ];
    }

    public function testDeliveredCancelIsRecordedAsRequested(): void
    {
        $this->backend->expects($this->once())->method('post')
            ->with(BackendClient::ENDPOINT_RXCANCEL)
            ->willReturn(['rxCancelRequestPkUuid' => 'cancel-req-1', 'cancelledStatus' => 'PENDING']);
        $this->queue->expects($this->once())->method('markComplete')->with(11);
        $this->tracker->expects($this->once())->method('markCancelRequested')
            ->with('med-test-0001', 'cancel-req-1', 'PENDING');

        $result = $this->worker(QueueWorker::TYPE_RXCANCEL, ['erxEprescriptionMedicationPKUuid' => 'med-test-0001'])
            ->drainOutbound();

        $this->assertSame(1, $result['processed']);
    }

    /** @return array<string, mixed> what api.php queues right after an RxNew goes */
    private function fillIndicatorPayload(): array
    {
        return [
            'transactionUuid' => 't',
            'erxEprescriptionMedicationPKUuid' => 'med-test-0001',
            'rxFillIndicatorTypeRef' => '1',
            'timeZone' => 'UTC',
        ];
    }

    public function testAcceptedFillIndicatorIsRecordedAsSet(): void
    {
        $this->backend->expects($this->once())->method('post')
            ->with(BackendClient::ENDPOINT_RXFILL_INDICATOR_CHANGE, $this->fillIndicatorPayload())
            ->willReturn([
                'rxFillIndicatorTypeRef' => '1',
                'transactionStatus' => ['status' => 'OK', 'statusMessage' => 'Fill indicator updated successfully'],
                'isValidationError' => false,
            ]);
        $this->queue->expects($this->once())->method('markComplete')->with(11);
        $this->tracker->expects($this->once())->method('markFillIndicatorSet')
            ->with('med-test-0001', '1', 'Fill indicator updated successfully');
        $this->tracker->expects($this->never())->method('markFillIndicatorFailed');

        $result = $this->worker(QueueWorker::TYPE_RXFILL_INDICATOR, $this->fillIndicatorPayload())->drainOutbound();

        $this->assertSame(1, $result['processed']);
    }

    public function testRefusedFillIndicatorIsParkedAndShownOnTheRx(): void
    {
        // A clinician without the RX_FILL_INDICATOR_CHANGE service level, say:
        // the backend answers 200 with a validation error, which is a refusal,
        // not an outage. The Rx itself went; only the reporting request failed.
        $this->backend->method('post')->willReturn([
            'isValidationError' => true,
            'validationErrorInfo' => [['errorMessage' => 'Clinician not permitted to change fill indicator']],
        ]);
        $this->queue->expects($this->never())->method('markComplete');
        $this->queue->expects($this->once())->method('markFailed')
            ->with(11, 'Clinician not permitted to change fill indicator', false, 3)
            ->willReturn(QueueRepository::STATUS_PARKED);
        $this->tracker->expects($this->never())->method('markFillIndicatorSet');
        $this->tracker->expects($this->once())->method('markFillIndicatorFailed')
            ->with('med-test-0001', '1', 'Clinician not permitted to change fill indicator');

        $result = $this->worker(QueueWorker::TYPE_RXFILL_INDICATOR, $this->fillIndicatorPayload())->drainOutbound();

        $this->assertSame(1, $result['failed']);
    }

    public function testRetryableOutageLeavesTheFillIndicatorQueued(): void
    {
        $this->backend->method('post')->willThrowException(new BackendException('down', 503));
        $this->queue->method('markFailed')->willReturn(QueueRepository::STATUS_PENDING);
        $this->tracker->expects($this->never())->method('markFillIndicatorFailed');
        $this->tracker->expects($this->never())->method('markFillIndicatorSet');

        $this->worker(QueueWorker::TYPE_RXFILL_INDICATOR, $this->fillIndicatorPayload())->drainOutbound();
    }

    public function testFailingToRecordASetFillIndicatorDoesNotMarkItFailed(): void
    {
        $this->backend->method('post')->willReturn(['rxFillIndicatorTypeRef' => '1']);
        $this->tracker->method('markFillIndicatorSet')->willThrowException(new \RuntimeException('db gone'));
        $this->queue->expects($this->once())->method('markComplete');
        $this->queue->expects($this->never())->method('markFailed');

        $result = $this->worker(QueueWorker::TYPE_RXFILL_INDICATOR, $this->fillIndicatorPayload())->drainOutbound();

        $this->assertSame(1, $result['processed']);
    }

    public function testDeliveredResponseClosesTheRequest(): void
    {
        $this->backend->method('post')->willReturn(['transactionStatus' => ['status' => 'IN_PROGRESS']]);
        $this->notifications->expects($this->once())->method('markResponded')->with('n-renew-1');

        $this->worker(QueueWorker::TYPE_RXRENEWAL_RESPOND, ['erxNotificationPkUuid' => 'n-renew-1'])->drainOutbound();
    }

    public function testApprovedRenewalIsWrittenToTheChartOnce(): void
    {
        $this->backend->method('post')->willReturn(['transactionStatus' => ['status' => 'IN_PROGRESS']]);
        $this->notifications->method('getRequestDetail')->with('n-renew-1')->willReturn($this->renewalCache());

        $worker = $this->worker(QueueWorker::TYPE_RXRENEWAL_RESPOND, $this->renewalPayload('APPROVED'));
        $worker->drainOutbound();
        // Cron replays: the same completed row comes round again.
        $worker->drainOutbound();

        $this->assertCount(1, $this->written, 'a replayed completion must not write a second script');
        $this->assertSame(4242, $this->written[0]['patient_id']);
        $this->assertSame('TEST-DRUG 10 MG tablet', $this->written[0]['drug']);
        $this->assertSame('30', $this->written[0]['quantity']);
        $this->assertSame(2, $this->written[0]['refills'], 'the refills the pharmacy asked for');
        $this->assertSame(5, $this->written[0]['provider_id']);
        $this->assertSame('rxrenewal.respond:n-renew-1', $this->written[0]['prescriptionguid']);
    }

    public function testDeniedRenewalWritesNothingToTheChart(): void
    {
        $this->backend->method('post')->willReturn([]);
        $this->notifications->method('getRequestDetail')->willReturn($this->renewalCache());
        $this->notifications->expects($this->once())->method('markResponded');

        $this->worker(QueueWorker::TYPE_RXRENEWAL_RESPOND, $this->renewalPayload('DENIED'))->drainOutbound();

        $this->assertSame([], $this->written);
    }

    public function testResponseForAnUnknownPatientWritesNothingToTheChart(): void
    {
        $this->backend->method('post')->willReturn([]);
        $this->notifications->method('getRequestDetail')->willReturn($this->renewalCache());
        $this->notifications->expects($this->once())->method('markResponded');

        $this->worker(QueueWorker::TYPE_RXRENEWAL_RESPOND, $this->renewalPayload('APPROVED'), null)->drainOutbound();

        $this->assertSame([], $this->written);
    }

    public function testChartWriteIsOffWhenTheSettingIsOff(): void
    {
        $this->backend->method('post')->willReturn([]);
        $this->notifications->method('getRequestDetail')->willReturn($this->renewalCache());

        $this->worker(
            QueueWorker::TYPE_RXRENEWAL_RESPOND,
            $this->renewalPayload('APPROVED'),
            4242,
            [GlobalConfig::WRITE_TO_PRESCRIPTIONS => '0']
        )->drainOutbound();

        $this->assertSame([], $this->written);
    }

    public function testApprovedChangeWritesTheRequestedMedication(): void
    {
        $this->backend->method('post')->willReturn([]);
        $cache = PharmacyRequestMapper::detailCache(PharmacyRequestMapper::TYPE_CHANGE, [
            'rxChangeRequestFromPharmacy' => [
                'medicationRequested' => [
                    [
                        'requestedMedicaitonId' => 7,
                        'drugDescription' => 'TEST-GENERIC 10 MG tablet',
                        'drugCoded' => ['productCode' => ['code' => '11111111111', 'qualifier' => 'ND']],
                        'quantity' => ['value' => '90', 'codeListQualifier' => '38'],
                        'sig' => ['sigText' => 'Synthetic directions for test use only'],
                        'numberOfRefills' => 3,
                    ],
                ],
                'acceptedResponseCodes' => ['APPROVED', 'VALIDATED', 'DENIED'],
            ],
        ]);
        $this->notifications->method('getRequestDetail')->with('n-change-1')->willReturn($cache);
        $payload = PharmacyRequestMapper::changeResponse(
            ['transactionUuid' => 't'],
            'n-change-1',
            ['decision' => 'APPROVED', 'requestedMedicationId' => '7'],
            $cache
        );

        $this->worker(QueueWorker::TYPE_RXCHANGE_RESPOND, $payload)->drainOutbound();

        $this->assertCount(1, $this->written);
        $this->assertSame('TEST-GENERIC 10 MG tablet', $this->written[0]['drug']);
        $this->assertSame('90', $this->written[0]['quantity']);
        $this->assertSame(3, $this->written[0]['refills']);
        $this->assertSame('NDC 11111111111', $this->written[0]['drug_info_erx']);
    }

    public function testFailingChartWriteDoesNotMarkTheResponseFailed(): void
    {
        $this->backend->method('post')->willReturn([]);
        $this->notifications->method('getRequestDetail')->willThrowException(new \RuntimeException('db gone'));
        $this->notifications->expects($this->once())->method('markResponded');
        $this->queue->expects($this->once())->method('markComplete');
        $this->queue->expects($this->never())->method('markFailed');

        $result = $this->worker(QueueWorker::TYPE_RXRENEWAL_RESPOND, $this->renewalPayload('APPROVED'))
            ->drainOutbound();

        $this->assertSame(1, $result['processed']);
    }

    public function testRefusalInsideA200IsParkedAndShownToThePrescriber(): void
    {
        $this->backend->method('post')->willReturn([
            'isValidationError' => true,
            'validationErrorInfo' => [['errorMessage' => 'Response code not accepted']],
        ]);
        $this->queue->expects($this->never())->method('markComplete');
        $this->queue->expects($this->once())->method('markFailed')
            ->with(11, 'Response code not accepted', false, 3)
            ->willReturn(QueueRepository::STATUS_PARKED);
        $this->notifications->expects($this->never())->method('markResponded');
        $this->notifications->expects($this->once())->method('markResponseFailed')
            ->with('n-change-1', 'Response code not accepted');

        $result = $this->worker(QueueWorker::TYPE_RXCHANGE_RESPOND, ['erxNotificationPkUuid' => 'n-change-1'])
            ->drainOutbound();

        $this->assertSame(1, $result['failed']);
    }

    public function testRetryableOutageLeavesTheCancelQueued(): void
    {
        $this->backend->method('post')->willThrowException(new BackendException('down', 503));
        $this->queue->method('markFailed')->willReturn(QueueRepository::STATUS_PENDING);
        $this->tracker->expects($this->never())->method('markCancelFailed');

        $this->worker(QueueWorker::TYPE_RXCANCEL, ['erxEprescriptionMedicationPKUuid' => 'med-test-0001'])
            ->drainOutbound();
    }

    public function testParkedCancelIsShownAsFailed(): void
    {
        $this->backend->method('post')->willThrowException(new BackendException('rejected', 400));
        $this->queue->method('markFailed')->willReturn(QueueRepository::STATUS_PARKED);
        $this->tracker->expects($this->once())->method('markCancelFailed')->with('med-test-0001', 'rejected');

        $this->worker(QueueWorker::TYPE_RXCANCEL, ['erxEprescriptionMedicationPKUuid' => 'med-test-0001'])
            ->drainOutbound();
    }

    public function testFailingToRecordADeliveredCancelDoesNotMarkItFailed(): void
    {
        $this->backend->method('post')->willReturn(['rxCancelRequestPkUuid' => 'cancel-req-1']);
        $this->tracker->method('markCancelRequested')->willThrowException(new \RuntimeException('db gone'));
        $this->queue->expects($this->once())->method('markComplete');
        $this->queue->expects($this->never())->method('markFailed');

        $result = $this->worker(QueueWorker::TYPE_RXCANCEL, ['erxEprescriptionMedicationPKUuid' => 'med-test-0001'])
            ->drainOutbound();

        $this->assertSame(1, $result['processed']);
    }


    /** @return array<string, mixed> what the prescriber reviewed, as api.php caches it */
    private function newRxCache(): array
    {
        return PharmacyRequestMapper::detailCache(PharmacyRequestMapper::TYPE_NEW_RX, [
            'rxNewRequestFromPharmacy' => [
                'patient' => ['firstName' => 'Test', 'lastName' => 'Patient'],
                'medicationRequested' => [
                    'drugDescription' => 'TEST-DRUG 10 MG tablet',
                    'drugCoded' => ['productCode' => ['code' => '00000000000', 'qualifier' => 'ND']],
                    'quantity' => ['value' => '30', 'codeListQualifier' => '38'],
                    'sig' => ['sigText' => 'Synthetic directions for test use only'],
                    'numberOfRefills' => '1',
                ],
            ],
            'erxPatientPKUuid' => 'erx-patient-new-0001',
        ]);
    }

    /** @return array<string, mixed> */
    private function newRxPayload(string $decision): array
    {
        return PharmacyRequestMapper::newRxResponse(
            ['transactionUuid' => 't'],
            'n-newrx-1',
            ['decision' => $decision, 'deniedReason' => 'AF'],
            'UTC'
        );
    }

    public function testApprovedNewRxRequestIsTrackedUnderTheBackendsMedicationAndCharted(): void
    {
        // The backend sends the script itself and names the medication it made.
        $this->backend->method('post')->willReturn([
            'eprescriptionMedicationPkUuid' => 'med-newrx-0001',
            'rxStatus' => 'SUCCESS',
            'transactionStatus' => ['status' => 'OK'],
        ]);
        $this->notifications->method('getRequestDetail')->with('n-newrx-1')->willReturn($this->newRxCache());
        $this->notifications->method('markResponded')->with('n-newrx-1');
        // recordSent upserts on the medication uuid, so the replay below
        // records the same row again rather than a second one.
        $this->tracker->expects($this->atLeastOnce())->method('recordSent')
            ->with('med-newrx-0001', 4242, 3, 5, 'TEST-DRUG 10 MG tablet', 'SUCCESS');

        $worker = $this->worker(QueueWorker::TYPE_NEWRX_REQUEST_RESPOND, $this->newRxPayload('APPROVED'));
        $worker->drainOutbound();
        $worker->drainOutbound();

        $this->assertCount(1, $this->written, 'a replayed completion must not write a second script');
        $this->assertSame(4242, $this->written[0]['patient_id']);
        $this->assertSame('TEST-DRUG 10 MG tablet', $this->written[0]['drug']);
        $this->assertSame('30', $this->written[0]['quantity']);
        $this->assertSame(1, $this->written[0]['refills']);
        $this->assertSame('newrxrequest.respond:n-newrx-1', $this->written[0]['prescriptionguid']);
    }

    public function testDeniedNewRxRequestTracksAndChartsNothing(): void
    {
        $this->backend->method('post')->willReturn(['transactionStatus' => ['status' => 'OK']]);
        $this->notifications->method('getRequestDetail')->willReturn($this->newRxCache());
        $this->notifications->expects($this->once())->method('markResponded');
        $this->tracker->expects($this->never())->method('recordSent');

        $this->worker(QueueWorker::TYPE_NEWRX_REQUEST_RESPOND, $this->newRxPayload('DENIED'))->drainOutbound();

        $this->assertSame([], $this->written);
    }

    public function testApprovedNewRxRequestWithoutAChartIsNotTracked(): void
    {
        $this->backend->method('post')->willReturn(['eprescriptionMedicationPkUuid' => 'med-newrx-0001']);
        $this->notifications->method('getRequestDetail')->willReturn($this->newRxCache());
        $this->tracker->expects($this->never())->method('recordSent');

        $this->worker(QueueWorker::TYPE_NEWRX_REQUEST_RESPOND, $this->newRxPayload('APPROVED'), null)->drainOutbound();

        $this->assertSame([], $this->written);
    }

    public function testRefusedNewRxResponseGoesBackToThePrescriber(): void
    {
        $this->backend->method('post')->willReturn([
            'isValidationError' => true,
            'validationErrorInfo' => [['errorMessage' => 'Clinician lacks NEW_RX_RESPONSE_DENIED']],
        ]);
        $this->queue->expects($this->never())->method('markComplete');
        $this->queue->method('markFailed')->willReturn(QueueRepository::STATUS_PARKED);
        $this->notifications->expects($this->never())->method('markResponded');
        $this->notifications->expects($this->once())->method('markResponseFailed')
            ->with('n-newrx-1', 'Clinician lacks NEW_RX_RESPONSE_DENIED');
        $this->tracker->expects($this->never())->method('recordSent');

        $result = $this->worker(QueueWorker::TYPE_NEWRX_REQUEST_RESPOND, $this->newRxPayload('DENIED'))
            ->drainOutbound();

        $this->assertSame(1, $result['failed']);
    }
}
