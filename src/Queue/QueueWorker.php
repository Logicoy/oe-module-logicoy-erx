<?php

/**
 * Drains the outbound queue and polls for inbound traffic.
 *
 * Inbound notifications can also be pushed to public/webhook.php where the
 * backend can reach the site; polling covers installs it cannot reach, and
 * both paths are safe to overlap. Outbound always goes through here. Run from
 * OpenEMR cron.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Queue;

use LogiCoy\ERx\Client\BackendClient;
use LogiCoy\ERx\Client\BackendException;
use LogiCoy\ERx\Config\CredentialStore;
use LogiCoy\ERx\Config\GlobalConfig;
use LogiCoy\ERx\Config\SessionContext;
use LogiCoy\ERx\Prescription\NotificationStore;
use LogiCoy\ERx\Prescription\PharmacyRequestMapper;
use LogiCoy\ERx\Prescription\PrescriptionTracker;
use LogiCoy\ERx\Prescription\PrescriptionWriter;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Logging\SystemLogger;
use Throwable;

class QueueWorker
{
    private const DEFAULT_BATCH_SIZE = 25;

    public const TYPE_RXCANCEL = 'rxcancel.request';
    public const TYPE_RXRENEWAL_RESPOND = 'rxrenewal.respond';
    public const TYPE_RXCHANGE_RESPOND = 'rxchange.respond';
    // The prescriber's answer to a pharmacy-initiated new Rx request. On an
    // approval the backend sends the pharmacy's medication as the script.
    public const TYPE_NEWRX_REQUEST_RESPOND = 'newrxrequest.respond';
    // Queued by the send path right after an RxNew goes: asks the backend to
    // have the pharmacy report the dispensing events the configured
    // indicator names. Keyed on the medication, so a resend queues one.
    public const TYPE_RXFILL_INDICATOR = 'rxfill.indicator';

    /** Outbound message types mapped to the backend endpoint that sends them. */
    private const OUTBOUND_ENDPOINTS = [
        'patient.add' => BackendClient::ENDPOINT_PATIENT_ADD,
        'patient.update' => BackendClient::ENDPOINT_PATIENT_UPDATE,
        'draft.add' => BackendClient::ENDPOINT_DRAFT_ADD,
        'draft.update' => BackendClient::ENDPOINT_DRAFT_UPDATE,
        'draft.remove' => BackendClient::ENDPOINT_DRAFT_REMOVE,
        'rxnew.send' => BackendClient::ENDPOINT_RXNEW_SEND,
        self::TYPE_RXCANCEL => BackendClient::ENDPOINT_RXCANCEL,
        self::TYPE_RXRENEWAL_RESPOND => BackendClient::ENDPOINT_RXRENEWAL_RESPOND,
        self::TYPE_RXCHANGE_RESPOND => BackendClient::ENDPOINT_RXCHANGE_RESPOND,
        self::TYPE_NEWRX_REQUEST_RESPOND => BackendClient::ENDPOINT_PHARMACY_NEW_RX_RESPOND,
        self::TYPE_RXFILL_INDICATOR => BackendClient::ENDPOINT_RXFILL_INDICATOR_CHANGE,
    ];

    /**
     * Types that reach a pharmacy, or change what it is asked to report. The
     * backend can refuse these inside an HTTP 200; completing the row anyway
     * would tell the prescriber the pharmacy was answered when it was not.
     */
    private const PHARMACY_BOUND = [
        self::TYPE_RXCANCEL,
        self::TYPE_RXRENEWAL_RESPOND,
        self::TYPE_RXCHANGE_RESPOND,
        self::TYPE_NEWRX_REQUEST_RESPOND,
        self::TYPE_RXFILL_INDICATOR,
    ];

    /** Responses to a pharmacy request, each closing the request it answers. */
    private const RESPONSE_TYPES = [
        self::TYPE_RXRENEWAL_RESPOND,
        self::TYPE_RXCHANGE_RESPOND,
        self::TYPE_NEWRX_REQUEST_RESPOND,
    ];

    /** Audit event per response type. */
    private const RESPONSE_AUDIT = [
        self::TYPE_RXRENEWAL_RESPOND => 'rxrenewal-response-sent',
        self::TYPE_RXCHANGE_RESPOND => 'rxchange-response-sent',
        self::TYPE_NEWRX_REQUEST_RESPOND => 'newrxrequest-response-sent',
    ];

    /** The request type each response answers, for the chart write. */
    private const RESPONSE_REQUEST_TYPE = [
        self::TYPE_RXRENEWAL_RESPOND => PharmacyRequestMapper::TYPE_RENEWAL,
        self::TYPE_RXCHANGE_RESPOND => PharmacyRequestMapper::TYPE_CHANGE,
        self::TYPE_NEWRX_REQUEST_RESPOND => PharmacyRequestMapper::TYPE_NEW_RX,
    ];

    private GlobalConfig $config;
    private QueueRepository $queue;
    private BackendClient $backend;
    private CredentialStore $credentials;
    private ?NotificationPoller $poller;
    private PrescriptionTracker $tracker;
    private NotificationStore $notifications;
    private SystemLogger $logger;
    private ?EventAuditLogger $auditLogger;
    private ?PrescriptionWriter $writer;

    public function __construct(
        GlobalConfig $config,
        QueueRepository $queue,
        BackendClient $backend,
        CredentialStore $credentials,
        ?NotificationPoller $poller = null,
        ?PrescriptionTracker $tracker = null,
        ?NotificationStore $notifications = null,
        ?SystemLogger $logger = null,
        ?EventAuditLogger $auditLogger = null,
        ?PrescriptionWriter $writer = null
    ) {
        $this->config = $config;
        $this->queue = $queue;
        $this->backend = $backend;
        $this->credentials = $credentials;
        $this->poller = $poller;
        $this->tracker = $tracker ?? new PrescriptionTracker();
        $this->notifications = $notifications ?? new NotificationStore();
        $this->logger = $logger ?? new SystemLogger();
        $this->auditLogger = $auditLogger;
        // Resolved lazily: the default writer reaches into OpenEMR's
        // PrescriptionService, which is not there at module load time.
        $this->writer = $writer;
    }

    /**
     * @return array{processed: int, failed: int, released: int}
     */
    public function drainOutbound(int $batchSize = self::DEFAULT_BATCH_SIZE): array
    {
        $released = $this->queue->releaseStale();
        $maxAttempts = $this->config->getQueueMaxAttempts();
        $rows = $this->queue->claimBatch(QueueRepository::DIRECTION_OUTBOUND, $batchSize, $maxAttempts);

        $processed = 0;
        $failed = 0;

        foreach ($rows as $row) {
            try {
                $this->sendOutbound($row);
                $processed++;
            } catch (BackendException $e) {
                // The queue row keeps the log message, with the endpoint and
                // HTTP status; the prescriber's card gets the backend's words.
                $status = $this->queue->markFailed((int)$row['id'], $e->getMessage(), $e->isRetryable(), $maxAttempts);
                $this->onFailed($row, $status, $e->getUserMessage());
                $failed++;
            } catch (Throwable $e) {
                // A non-backend failure is a bug on our side; do not keep retrying it.
                $status = $this->queue->markFailed((int)$row['id'], $e->getMessage(), false, $maxAttempts);
                $this->onFailed($row, $status, $e->getMessage());
                $failed++;
            }
        }

        return ['processed' => $processed, 'failed' => $failed, 'released' => $released];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function sendOutbound(array $row): void
    {
        $messageType = (string)$row['message_type'];
        $endpoint = self::OUTBOUND_ENDPOINTS[$messageType] ?? null;

        if ($endpoint === null) {
            throw new BackendException('Unknown outbound message type: ' . $messageType);
        }

        $credential = $this->credentials->getCredential(
            (int)$row['facility_id'],
            $row['provider_user_id'] === null ? null : (int)$row['provider_user_id']
        );

        if ($credential === null) {
            throw new BackendException(
                'No active LogiCoy credential for facility ' . (int)$row['facility_id']
            );
        }

        $payload = $this->queue->getPayload((int)$row['id']);
        $response = $this->backend->post($endpoint, $payload, $credential);

        if (in_array($messageType, self::PHARMACY_BOUND, true)) {
            $failure = BackendClient::reportedFailure($response);
            if ($failure !== null) {
                // A refusal, not an outage: retrying the same payload gets the
                // same answer, so it is parked for the prescriber to see.
                throw new BackendException($failure, 422, ['endpoint' => $endpoint]);
            }
        }

        $this->queue->markComplete((int)$row['id'], $response);

        // It has been delivered and cannot be taken back. A failure recording
        // that must not reach drainOutbound's handler, which would mark the
        // delivered row failed and tell the prescriber it never went.
        try {
            $this->onComplete($row, $payload, $response);
        } catch (Throwable $e) {
            $this->logger->error('LogiCoy transmission delivered but not recorded', [
                'queue_id' => (int)$row['id'],
                'message_type' => $messageType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reflect a delivered transmission onto what the prescriber sees.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $response
     */
    private function onComplete(array $row, array $payload, array $response): void
    {
        $type = (string)$row['message_type'];
        $patientId = $row['patient_id'] === null ? null : (int)$row['patient_id'];

        if ($type === self::TYPE_RXCANCEL) {
            $medicationUuid = (string)($payload['erxEprescriptionMedicationPKUuid'] ?? '');
            $this->tracker->markCancelRequested(
                $medicationUuid,
                (string)($response['rxCancelRequestPkUuid'] ?? ''),
                trim((string)($response['cancelledStatus'] ?? '') . ' ' . (string)($response['description'] ?? ''))
            );
            $this->audit('rxcancel-sent', 'RxCancel transmitted for medication ' . $medicationUuid, $patientId);
            return;
        }

        if ($type === self::TYPE_RXFILL_INDICATOR) {
            $medicationUuid = (string)($payload['erxEprescriptionMedicationPKUuid'] ?? '');
            // The backend echoes the code it set; the one asked for stands in
            // when it does not.
            $indicator = (string)($response['rxFillIndicatorTypeRef'] ?? $payload['rxFillIndicatorTypeRef'] ?? '');
            $this->tracker->markFillIndicatorSet(
                $medicationUuid,
                $indicator,
                BackendClient::statusMessage($response)
            );
            $this->audit(
                'rxfill-indicator-sent',
                'Fill indicator ' . $indicator . ' set for medication ' . $medicationUuid,
                $patientId
            );
            return;
        }

        if (in_array($type, self::RESPONSE_TYPES, true)) {
            $notificationUuid = (string)($payload['erxNotificationPkUuid'] ?? '');
            $this->notifications->markResponded($notificationUuid);
            $this->audit(
                self::RESPONSE_AUDIT[$type],
                'Response transmitted for pharmacy request ' . $notificationUuid,
                $patientId
            );
            if ($type === self::TYPE_NEWRX_REQUEST_RESPOND) {
                $this->recordNewRxApproval($row, $payload, $response, $patientId);
            }
            $this->reflectResponse($row, $type, $notificationUuid, $payload, $patientId);
        }
    }

    /**
     * An approved new Rx request is a prescription the backend has now sent,
     * with a medication uuid of its own: tracked like one sent from the
     * prescribing screen, so its verification, fill and cancel traffic lands
     * on it. A denial sends nothing.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $response
     */
    private function recordNewRxApproval(array $row, array $payload, array $response, ?int $patientId): void
    {
        $decision = strtoupper((string)($payload['rxPharmacyIntiNewRxInfo']['responseCode'] ?? ''));
        $medicationUuid = (string)($response['eprescriptionMedicationPkUuid'] ?? '');
        if ($decision !== PharmacyRequestMapper::APPROVED || $medicationUuid === '' || $patientId === null) {
            return;
        }

        $requested = PharmacyRequestMapper::approvedMedication(
            PharmacyRequestMapper::TYPE_NEW_RX,
            $payload,
            $this->notifications->getRequestDetail((string)($payload['erxNotificationPkUuid'] ?? ''))
        );
        $this->tracker->recordSent(
            $medicationUuid,
            $patientId,
            (int)$row['facility_id'],
            $row['provider_user_id'] === null ? null : (int)$row['provider_user_id'],
            (string)($requested['drugName'] ?? ''),
            (string)($response['rxStatus'] ?? '')
        );
        $this->audit(
            'rxnew',
            'New Rx request approved; medication ' . $medicationUuid . ' sent by LogiCoy',
            $patientId
        );
    }

    /**
     * Put the script an approved response issues on the patient's chart.
     *
     * The chart's Prescriptions card reads OpenEMR's own prescriptions
     * table. Keyed on the queue row's message id, so a replayed completion
     * finds the row it already wrote. The pharmacy has been answered by now,
     * so a chart write that fails is logged, never allowed to mark the
     * response as not sent.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $payload
     */
    private function reflectResponse(
        array $row,
        string $type,
        string $notificationUuid,
        array $payload,
        ?int $patientId
    ): void {
        if ($patientId === null || $notificationUuid === '' || !$this->config->shouldWriteToPrescriptions()) {
            return;
        }

        try {
            $medication = PharmacyRequestMapper::approvedMedication(
                self::RESPONSE_REQUEST_TYPE[$type],
                $payload,
                $this->notifications->getRequestDetail($notificationUuid)
            );
            if ($medication === null) {
                return;
            }
            if ($row['provider_user_id'] !== null) {
                $medication['providerUserId'] = (int)$row['provider_user_id'];
            }

            $messageId = (string)($row['logicoy_message_id'] ?? '') ?: $type . ':' . $notificationUuid;
            ($this->writer ??= new PrescriptionWriter())->reflect($messageId, $patientId, $medication);
        } catch (Throwable $e) {
            $this->logger->error('LogiCoy response transmitted but the script was not written to the chart', [
                'queue_id' => (int)$row['id'],
                'message_type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Once a pharmacy-bound transmission is parked it will not be retried, so
     * the prescriber has to see that it did not go — the Rx shows the cancel
     * failed and the request goes back to awaiting a response.
     *
     * @param array<string, mixed> $row
     */
    private function onFailed(array $row, string $status, string $error): void
    {
        if ($status !== QueueRepository::STATUS_PARKED) {
            return;
        }

        try {
            $payload = $this->queue->getPayload((int)$row['id']);
            $type = (string)$row['message_type'];

            if ($type === self::TYPE_RXCANCEL) {
                $this->tracker->markCancelFailed((string)($payload['erxEprescriptionMedicationPKUuid'] ?? ''), $error);
            } elseif ($type === self::TYPE_RXFILL_INDICATOR) {
                $this->tracker->markFillIndicatorFailed(
                    (string)($payload['erxEprescriptionMedicationPKUuid'] ?? ''),
                    (string)($payload['rxFillIndicatorTypeRef'] ?? ''),
                    $error
                );
            } elseif (in_array($type, self::RESPONSE_TYPES, true)) {
                $this->notifications->markResponseFailed((string)($payload['erxNotificationPkUuid'] ?? ''), $error);
            }
        } catch (Throwable $e) {
            $this->logger->error('LogiCoy could not record a failed transmission', [
                'queue_id' => (int)$row['id'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Poll the backend for pharmacy notifications for one facility.
     *
     * @return array{fetched: int, processed: int, replayed: int, skipped: int, failed: int}
     */
    public function pollInbound(int $facilityId): array
    {
        $none = ['fetched' => 0, 'processed' => 0, 'replayed' => 0, 'skipped' => 0, 'failed' => 0];

        if (!$this->config->isPollEnabled()) {
            return $none;
        }

        $credential = $this->credentials->getCredential($facilityId);
        if ($credential === null) {
            return $none;
        }

        $poller = $this->poller ??= new NotificationPoller(
            $this->config,
            $this->backend,
            $this->notifications,
            $this->tracker,
            $this->logger,
            $this->auditLogger
        );

        return $poller->poll($credential, $facilityId);
    }

    /**
     * The comment carries identifiers only — never the drug or the sig.
     */
    private function audit(string $event, string $comment, ?int $patientId): void
    {
        ($this->auditLogger ??= EventAuditLogger::getInstance())->newEvent(
            'logicoy-erx-' . $event,
            SessionContext::getString('authUser') ?: 'logicoy-erx-worker',
            SessionContext::getString('authProvider') ?: 'Default',
            1,
            $comment,
            $patientId
        );
    }
}
