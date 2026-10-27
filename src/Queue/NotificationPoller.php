<?php

/**
 * Polls /v1/notifications/get and routes each notification.
 *
 * Pharmacy traffic — renewal and change requests, cancel answers, fill events,
 * verification results — arrives two ways: collected here from cron, or pushed
 * by the backend to public/webhook.php, which hands each notification to
 * process(). Either path can deliver the same notification more than once, so
 * each is recorded by its LogiCoy notification uuid and its follow-up runs
 * once:
 *
 * - renewal / change request: opened for the prescriber; nothing is sent
 * - pharmacy-initiated new Rx request: opened for the prescriber, after
 *                             /pharmacy-initiated/new-rx/get-details, whose
 *                             patient is matched to a chart when known here
 * - cancel response:          /rx-cancel/check-status, applied to the Rx
 * - fill events:              /rx-fill/check-status, applied to the Rx
 * - RxNew verification:       /rxnew/get-pending-verification-status
 * - anything else:            recorded for the log
 *
 * A replayed poll records nothing new and calls nothing twice.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Queue;

use DateTimeImmutable;
use DateTimeZone;
use LogiCoy\ERx\Client\BackendClient;
use LogiCoy\ERx\Client\BackendException;
use LogiCoy\ERx\Config\GlobalConfig;
use LogiCoy\ERx\Config\SessionContext;
use LogiCoy\ERx\Prescription\NotificationStore;
use LogiCoy\ERx\Prescription\NotificationStoreInterface;
use LogiCoy\ERx\Prescription\PharmacyRequestMapper;
use LogiCoy\ERx\Prescription\PrescribingService;
use LogiCoy\ERx\Prescription\PrescriptionTracker;
use LogiCoy\ERx\Prescription\PrescriptionTrackerInterface;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Logging\SystemLogger;
use Throwable;

class NotificationPoller
{
    public const PAGE_SIZE = 50;

    /** A ceiling, so a backend that never reports a last page cannot loop. */
    public const MAX_PAGES = 20;

    public const TYPE_RENEWAL_REQUEST = 'RX_RENEWAL_REQUEST';
    public const TYPE_CHANGE_REQUEST = 'RX_CHANGE_REQUEST';
    public const TYPE_CANCEL_RESPONSE = 'RX_CANCEL_RESPONSE';
    public const TYPE_RX_NEW = 'RX_NEW';
    /** A pharmacy asking the prescriber for a new prescription. */
    public const TYPE_NEW_RX_REQUEST = 'NEW_RX_REQUEST';

    /** Notifications that are the prescriber's to answer. */
    private const REQUEST_TYPES = [
        self::TYPE_RENEWAL_REQUEST,
        self::TYPE_CHANGE_REQUEST,
        self::TYPE_NEW_RX_REQUEST,
    ];

    private const FILL_TYPES = ['RX_FILL', 'RX_FILL_STATUS', 'RX_FILL_INDICATOR_CHANGE'];

    private GlobalConfig $config;
    private BackendClient $backend;
    private NotificationStoreInterface $store;
    private PrescriptionTrackerInterface $tracker;
    private SystemLogger $logger;
    private ?EventAuditLogger $auditLogger;

    /** Who the audit trail names when no user is signed in. */
    private string $actor;

    public function __construct(
        GlobalConfig $config,
        BackendClient $backend,
        ?NotificationStoreInterface $store = null,
        ?PrescriptionTrackerInterface $tracker = null,
        ?SystemLogger $logger = null,
        ?EventAuditLogger $auditLogger = null,
        string $actor = 'logicoy-erx-worker'
    ) {
        $this->config = $config;
        $this->backend = $backend;
        $this->store = $store ?? new NotificationStore();
        $this->tracker = $tracker ?? new PrescriptionTracker();
        $this->logger = $logger ?? new SystemLogger();
        $this->auditLogger = $auditLogger;
        $this->actor = $actor;
    }

    /**
     * @param array<string, mixed> $credential the facility's practice credential
     * @return array{fetched: int, processed: int, replayed: int, skipped: int, failed: int}
     */
    public function poll(array $credential, int $facilityId): array
    {
        $counts = ['fetched' => 0, 'processed' => 0, 'replayed' => 0, 'skipped' => 0, 'failed' => 0];

        $registration = trim((string)($credential['erx_client_registration_uuid'] ?? ''));
        if ($registration === '') {
            // Without it the backend cannot say whose notifications to return.
            throw new BackendException(
                'No client registration uuid for facility ' . $facilityId . '; notifications cannot be polled'
            );
        }

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->backend->post(BackendClient::ENDPOINT_NOTIFICATIONS, [
                'transactionUuid' => PrescribingService::newTransactionId(),
                'clientRegistrationPkUuid' => $registration,
                'pagination' => ['pageNumber' => $page, 'numberOfResultPerPage' => self::PAGE_SIZE],
            ], $credential);

            $list = $response['pharmacyInitiatedTxNotificationList']
                ?? $response['data']['pharmacyInitiatedTxNotificationList']
                ?? [];
            $list = is_array($list) ? $list : [];

            foreach ($list as $notification) {
                $counts['fetched']++;
                $outcome = is_array($notification)
                    ? $this->process($notification, $credential, $facilityId)
                    : 'skipped';
                $counts[$outcome]++;
            }

            if (self::isLastPage($response, count($list), $page)) {
                break;
            }
        }

        return $counts;
    }

    /**
     * Record one notification and run its follow-up once.
     *
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $credential
     * @return 'processed'|'replayed'|'skipped'|'failed'
     */
    public function process(array $raw, array $credential, int $facilityId): string
    {
        $uuid = trim((string)($raw['erxNotificationPkUuid'] ?? ''));
        if ($uuid === '') {
            $this->logger->warning('LogiCoy notification without an erxNotificationPkUuid; skipping');
            return 'skipped';
        }

        // One client registration can span several practices. Each facility
        // polls with its own credential and keeps only its own practice's
        // notifications, so a multi-facility install does not file one
        // practice's refill requests under another.
        $practice = trim((string)($raw['practicePkUuid'] ?? ''));
        $ownPractice = trim((string)($credential['erx_practice_uuid'] ?? ''));
        if ($practice !== '' && $ownPractice !== '' && $practice !== $ownPractice) {
            return 'skipped';
        }

        $type = strtoupper(trim((string)($raw['rxTypeName'] ?? '')));
        $medicationUuid = trim((string)($raw['erxEprescriptionMedicationPKUuid'] ?? ''));
        $patientUuid = trim((string)($raw['patientPKUuid'] ?? ''));

        $pid = $this->tracker->findPid($medicationUuid)
            ?? $this->store->resolvePatientId($patientUuid, $facilityId);

        $isRequest = in_array($type, self::REQUEST_TYPES, true) && ($raw['isReplyRequired'] ?? true) !== false;

        $row = $this->store->record([
            'uuid' => $uuid,
            'facility_id' => $facilityId,
            'pid' => $pid,
            'rx_type' => $type,
            'medication_uuid' => $medicationUuid,
            'patient_uuid' => $patientUuid,
            'clinician_uuid' => (string)($raw['clinicianPKUuid'] ?? ''),
            'patient_name' => (string)($raw['patientFullName'] ?? ''),
            'clinician_name' => (string)($raw['clinicianFullName'] ?? ''),
            'message' => (string)($raw['notificationMessage'] ?? $raw['erxMessage'] ?? ''),
            'priority' => (string)($raw['priorityType'] ?? ''),
            'reply_required' => $isRequest,
            'requested_at' => self::toDateTime($raw['requestedDate'] ?? null),
            'due_at' => self::toDateTime($raw['dueOnDatetime'] ?? null),
            'state' => $isRequest ? NotificationStore::STATE_OPEN : NotificationStore::STATE_INFO,
        ]);

        if (!empty($row['processed_at'])) {
            return 'replayed';
        }

        try {
            $this->followUp($type, $uuid, $medicationUuid, $raw, $credential, $facilityId, $pid);
            $this->store->markProcessed($uuid);
            return 'processed';
        } catch (Throwable $e) {
            // Left unprocessed; the next poll or redelivered push tries again,
            // up to the queue's attempt limit. The message is ours or the backend's status
            // envelope — never the notification body.
            $this->store->markProcessingFailed($uuid, $e->getMessage(), $this->config->getQueueMaxAttempts());
            $this->logger->error('LogiCoy notification follow-up failed', [
                'erx_notification_uuid' => $uuid,
                'rx_type' => $type,
                'error' => $e->getMessage(),
            ]);
            return 'failed';
        }
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $credential
     */
    private function followUp(
        string $type,
        string $uuid,
        string $medicationUuid,
        array $raw,
        array $credential,
        int $facilityId,
        ?int $pid
    ): void {
        // The status lookups need a clinician. The notification names the one
        // it concerns; the practice credential the worker holds may name none.
        $context = PrescribingService::requestContext($credential, trim((string)($raw['clinicianPKUuid'] ?? '')));

        if ($type === self::TYPE_NEW_RX_REQUEST) {
            // The push names no patient: the pharmacy's patient may not exist
            // on the eRx side until get-details creates them. Fetched now so
            // the inbox can say who the request is for and whether they have
            // a chart here, before anyone opens it.
            $this->fetchNewRxRequest($uuid, $context, $credential, $facilityId, $pid);
            return;
        }

        if (in_array($type, self::REQUEST_TYPES, true)) {
            // The prescriber answers these from the screen. Nothing to fetch
            // until they open it.
            return;
        }

        if ($type === self::TYPE_CANCEL_RESPONSE) {
            $response = $this->lookup(BackendClient::ENDPOINT_RXCANCEL_STATUS, $context, $uuid, $credential);
            $status = (string)($response['rxCancelResponseStatus'] ?? '');
            $code = (string)($response['rxCancelResponseCode'] ?? '');
            $outcome = PrescriptionTracker::cancelOutcome($status, $code);
            $detail = trim($status . ' ' . ($response['rxCancelResponseReason'] ?? ''));

            if ($medicationUuid !== '') {
                $this->tracker->applyCancelResponse($medicationUuid, $outcome, $detail);
                $this->audit('rxcancel-response', 'RxCancel answered by pharmacy (' . $outcome . ') for medication '
                    . $medicationUuid, $pid);
            }

            return;
        }

        if (in_array($type, self::FILL_TYPES, true)) {
            $response = $this->lookup(BackendClient::ENDPOINT_RXFILL_STATUS, $context, $uuid, $credential);
            $fill = (string)($response['rxFillStatus'] ?? '');

            if ($medicationUuid !== '' && $fill !== '') {
                $this->tracker->applyFillStatus($medicationUuid, $fill, (string)($response['rxFillDescription'] ?? ''));
                $this->audit('rxfill', 'Fill status ' . $fill . ' for medication ' . $medicationUuid, $pid);
            }

            return;
        }

        if ($type === self::TYPE_RX_NEW) {
            $response = $this->lookup(BackendClient::ENDPOINT_RXNEW_VERIFICATION_STATUS, $context, $uuid, $credential);
            $status = (string)($response['verificationStatus'] ?? '');

            if ($medicationUuid !== '' && $status !== '') {
                $this->tracker->applyTransmissionStatus(
                    $medicationUuid,
                    $status,
                    (string)($response['verificationStatusMessage'] ?? '')
                );
            }
        }
    }

    /**
     * Record who a pharmacy-initiated new Rx request is for.
     *
     * The patient is matched to a chart only through this module's own
     * patient map (a patient synced from here). A name and birth date match
     * is offered on the screen for a person to confirm, never made here.
     *
     * @param array<string, string> $context
     * @param array<string, mixed> $credential
     */
    private function fetchNewRxRequest(
        string $uuid,
        array $context,
        array $credential,
        int $facilityId,
        ?int $pid
    ): void {
        $response = $this->lookup(BackendClient::ENDPOINT_PHARMACY_NEW_RX_GET, $context, $uuid, $credential);
        $details = PharmacyRequestMapper::newRxDetails($response);
        $erxPatientUuid = $details['erxPatientUuid'];

        $this->store->saveRequestDetail($uuid, PharmacyRequestMapper::detailCache(
            PharmacyRequestMapper::TYPE_NEW_RX,
            $response
        ));
        $this->store->attachPatient(
            $uuid,
            $pid ?? $this->store->resolvePatientId($erxPatientUuid, $facilityId),
            $erxPatientUuid,
            $details['patient']['name']
        );
    }

    /**
     * @param array<string, string> $context
     * @param array<string, mixed> $credential
     * @return array<string, mixed>
     */
    private function lookup(string $endpoint, array $context, string $notificationUuid, array $credential): array
    {
        // The status endpoints spell it erxNotificationPKUuid, the request
        // endpoints erxNotificationPkUuid. Both are sent; each reads its own.
        $response = $this->backend->post(
            $endpoint,
            $context + ['erxNotificationPKUuid' => $notificationUuid, 'erxNotificationPkUuid' => $notificationUuid],
            $credential
        );

        $failure = BackendClient::reportedFailure($response);
        if ($failure !== null) {
            throw new BackendException($failure, 422, ['endpoint' => $endpoint]);
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $response
     */
    private static function isLastPage(array $response, int $count, int $page): bool
    {
        if ($count < self::PAGE_SIZE) {
            return true;
        }

        $pagination = is_array($response['pagination'] ?? null) ? $response['pagination'] : [];
        if (($pagination['last'] ?? null) === true) {
            return true;
        }
        if (isset($pagination['totalPages']) && is_numeric($pagination['totalPages'])) {
            return $page + 1 >= (int)$pagination['totalPages'];
        }

        return false;
    }

    /**
     * The backend sends ISO 8601 in UTC; OpenEMR stores local DATETIME.
     */
    public static function toDateTime(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Anything that changes what is known about a prescription is audited.
     * The comment carries identifiers only — never the drug or the sig.
     *
     * Not caught: if the audit write fails the notification stays unprocessed
     * and is retried, and the tracker update it follows is safe to repeat.
     */
    private function audit(string $event, string $comment, ?int $pid): void
    {
        ($this->auditLogger ??= EventAuditLogger::getInstance())->newEvent(
            'logicoy-erx-' . $event,
            SessionContext::getString('authUser') ?: $this->actor,
            SessionContext::getString('authProvider') ?: 'Default',
            1,
            $comment,
            $pid
        );
    }
}
