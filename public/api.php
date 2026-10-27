<?php

/**
 * JSON endpoint behind the prescribing screen.
 *
 * Thin: authorises, resolves the practice credential, delegates to the eRx
 * backend, and returns JSON. No clinical logic.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';

use LogiCoy\ERx\Bootstrap;
use LogiCoy\ERx\Client\BackendClient;
use LogiCoy\ERx\Client\BackendException;
use LogiCoy\ERx\Config\CredentialStore;
use LogiCoy\ERx\Config\FacilityResolver;
use LogiCoy\ERx\Config\GlobalConfig;
use LogiCoy\ERx\Config\ProviderProfileStore;
use LogiCoy\ERx\Config\SessionContext;
use LogiCoy\ERx\Identity\IdmeMfaService;
use LogiCoy\ERx\Identity\IdmeMfaSessionStore;
use LogiCoy\ERx\Identity\IdmeVerificationStore;
use LogiCoy\ERx\Prescription\DraftMapper;
use LogiCoy\ERx\Prescription\NotificationStore;
use LogiCoy\ERx\Prescription\PatientSync;
use LogiCoy\ERx\Prescription\PharmacyRequestMapper;
use LogiCoy\ERx\Prescription\PrescribingService;
use LogiCoy\ERx\Prescription\PrescriptionTracker;
use LogiCoy\ERx\Prescription\PrescriptionWriter;
use LogiCoy\ERx\Prescription\RequestPatientLinker;
use LogiCoy\ERx\Prescription\RxPharmacyStore;
use LogiCoy\ERx\Queue\BackgroundSync;
use LogiCoy\ERx\Queue\QueueRepository;
use LogiCoy\ERx\Queue\QueueWorker;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;

header('Content-Type: application/json');

$respond = static function (int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
};

if (!AclMain::aclCheckCore(Bootstrap::ACL_SECTION, Bootstrap::ACL_SUBSECTION)) {
    $respond(403, ['error' => xl('You are not authorized to prescribe.')]);
}

// Every action here either reads PHI or transmits a prescription.
$session = SessionWrapperFactory::getInstance()->getActiveSession();
if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '', $session)) {
    $respond(400, ['error' => xl('Invalid security token. Reload the screen.')]);
}

$config = new GlobalConfig($GLOBALS);
if (!$config->isModuleEnabled()) {
    $respond(409, ['error' => xl('The LogiCoy eRx module is not enabled.')]);
}

$pid = (int)($_REQUEST['pid'] ?? 0) ?: SessionContext::getInt('pid');
$userId = SessionContext::getInt('authUserID');
$authUser = SessionContext::getString('authUser');
$facilityId = (new FacilityResolver())->resolve(
    SessionContext::values(['authUserID', 'facilityId', FacilityResolver::SESSION_KEY]),
    $GLOBALS
);

$credentials = new CredentialStore();

// The ID.me screen works per facility and names the one it means. Honoured
// only for a facility this user is registered at, so the parameter cannot be
// used to act under someone else's registration.
$pickedFacility = (int)($_REQUEST['facility_id'] ?? 0);
if ($pickedFacility > 0 && $pickedFacility !== $facilityId && $userId > 0) {
    $registeredAt = array_column($credentials->listPrescribingFacilities($userId), 'facility_id');
    if (in_array($pickedFacility, $registeredAt, true)) {
        $facilityId = $pickedFacility;
    }
}

$credential = $credentials->getCredential($facilityId, $userId ?: null);

if ($credential === null) {
    $respond(409, [
        'error' => xl('No LogiCoy credential is loaded for this facility.'),
        // Which facility got resolved depends on who is signed in, so both go in
        // the message rather than leaving the prescriber to guess.
        'hint' => xl('Signed in as') . ' ' . $authUser . ', '
            . xl('facility') . ' ' . $facilityId . '. '
            . xl('An administrator loads a credential for it under Miscellaneous, LogiCoy eRx, Settings.'),
    ]);
}

// Every eRx call below carries the prescriber. Without one the backend answers
// "Clinician User UUID is mandatory", which says nothing about what to do; the
// acting user simply has no LogiCoy prescriber registration for this facility.
if (CredentialStore::clinicianUuid($credential) === '') {
    $respond(409, [
        // Naming the account and the facility saves guessing at which of the two
        // is wrong — usually it is being signed in as an administrator.
        'error' => xl('You are signed in as') . ' ' . $authUser . ', '
            . xl('who is not registered as a prescriber with LogiCoy for this facility')
            . ' (' . xl('facility') . ' ' . $facilityId . ').',
        'hint' => xl('An administrator registers prescribers under Miscellaneous, LogiCoy eRx, Settings, step 3.'),
    ]);
}

$backend = new BackendClient($config, $credentials);
$service = new PrescribingService($backend);
$clinicianUuid = CredentialStore::clinicianUuid($credential);
$action = (string)($_REQUEST['action'] ?? '');

// Transmitting, cancelling and answering a pharmacy are audited clinical
// actions. The comment carries identifiers only — never the drug or the sig.
$audit = static function (string $event, string $comment, ?int $patientId) use ($authUser): void {
    EventAuditLogger::getInstance()->newEvent(
        'logicoy-erx-' . $event,
        $authUser,
        SessionContext::getString('authProvider'),
        1,
        $comment,
        $patientId
    );
};

// A request the backend refused inside an HTTP 200 is still a refusal.
// Something was queued: make sure the background service exists to send it
// and runs on OpenEMR's next tick. Never fails the request; the message is
// queued either way.
$sendSoon = static function (): void {
    try {
        BackgroundSync::ensureRegistered();
        BackgroundSync::runSoon();
    } catch (Throwable $e) {
        (new SystemLogger())->error('LogiCoy eRx background sync not scheduled', ['error' => $e->getMessage()]);
    }
};

$requireSuccess = static function (array $response) use ($respond): void {
    $failure = BackendClient::reportedFailure($response);
    if ($failure !== null) {
        $respond(502, ['error' => $failure]);
    }
};

// Controlled substances. Whether this prescriber may send one is read from
// the module's own records — EPCS registration, ID.me verification and the
// current MFA session — never from a backend call. The backend applies the
// same checks on send; this is so a refusal is explained before the call, and
// so the screens can say how long the MFA session has left.
$controlledDecision = static function () use ($facilityId, $userId): array {
    $verification = $userId > 0 && QueryUtils::existsTable(IdmeVerificationStore::TABLE)
        ? (new IdmeVerificationStore())->find($facilityId, $userId)
        : null;
    $mfa = $userId > 0 && QueryUtils::existsTable(IdmeMfaSessionStore::TABLE)
        ? (new IdmeMfaSessionStore())->find($facilityId, $userId)
        : null;

    return IdmeMfaService::controlledSendDecision(
        $userId > 0 && (new ProviderProfileStore())->isEpcs($userId),
        $verification,
        $mfa
    );
};
$reasonMessage = static fn(?string $reason): string => match ($reason) {
    IdmeMfaService::REASON_NOT_EPCS
        => xl('Your eRx prescribing type is not EPCS, so controlled substances cannot be prescribed under it.'),
    IdmeMfaService::REASON_NOT_VERIFIED => xl('Your identity is not verified with ID.me for this facility.'),
    IdmeMfaService::REASON_MFA_EXPIRED
        => xl('Your ID.me two-factor session has expired. Complete it again before sending a controlled substance.'),
    IdmeMfaService::REASON_MFA_REQUIRED
        => xl('Complete ID.me two-factor authentication before sending a controlled substance.'),
    default => '',
};
$decisionPayload = static fn(array $decision): array => [
    'allowed' => $decision['allowed'],
    'reason' => $decision['reason'],
    'message' => $reasonMessage($decision['reason']),
    'mfa' => $decision['mfa'],
];

// The pharmacy a draft was saved with, so the screen can name it when asking
// to send or cancel. Display only, so a failure here never fails the call
// it rides on: the screen falls back to "the pharmacy".
$rememberPharmacy = static function (string $medicationUuid, array $form): void {
    try {
        (new RxPharmacyStore())->remember(
            $medicationUuid,
            (string)($form['pharmacyOrgId'] ?? ''),
            (string)($form['pharmacyName'] ?? '')
        );
    } catch (Throwable $e) {
        (new SystemLogger())->error('LogiCoy draft pharmacy not recorded', [
            'medication' => $medicationUuid,
            'error' => $e->getMessage(),
        ]);
    }
};
$withPharmacyNames = static function (array $rows): array {
    try {
        $names = (new RxPharmacyStore())->namesFor(array_column($rows, 'medicationUuid'));
    } catch (Throwable $e) {
        (new SystemLogger())->error('LogiCoy draft pharmacies not read', ['error' => $e->getMessage()]);
        $names = [];
    }

    return array_map(
        static fn(array $r): array => $r + ['pharmacyName' => $names[$r['medicationUuid']] ?? ''],
        $rows
    );
};

// Ask the pharmacy to report how it dispenses a sent prescription. The API
// takes the fill indicator only once the Rx has been sent, so it is a
// separate call, and it is queued rather than made here: the screen must
// not wait on, or fail over, a request about reporting. Keyed on the
// medication, so a double click or a retried send queues one; a request the
// backend refused is revived with the current setting. Null when the setting
// is off; otherwise whether a row was queued this time.
$queueFillIndicator = static function (
    string $medicationUuid,
    PrescriptionTracker $tracker
) use (
    $config,
    $credential,
    $pid,
    $facilityId,
    $userId,
    $audit,
    $sendSoon
): ?array {
    $fillIndicator = $config->getFillIndicator();
    if ($fillIndicator === '') {
        return null;
    }

    $queued = (new QueueRepository())->enqueueOrRevive(
        QueueWorker::TYPE_RXFILL_INDICATOR,
        QueueWorker::TYPE_RXFILL_INDICATOR . ':' . $medicationUuid,
        PrescribingService::requestContext($credential) + [
            'erxEprescriptionMedicationPKUuid' => $medicationUuid,
            'rxFillIndicatorTypeRef' => $fillIndicator,
            'timeZone' => date_default_timezone_get(),
        ],
        $pid,
        $facilityId,
        $userId ?: null
    );
    if ($queued['queued']) {
        $tracker->markFillIndicatorQueued($medicationUuid, $fillIndicator);
        $audit(
            'rxfill-indicator',
            'Fill indicator ' . $fillIndicator . ' queued for medication ' . $medicationUuid,
            $pid
        );
    }
    $sendSoon();

    return ['queued' => $queued['queued'], 'indicator' => $fillIndicator];
};

try {
    if ($action === 'drug-search') {
        $term = trim((string)($_REQUEST['term'] ?? ''));
        $digits = preg_replace('/\D/', '', $term) ?? '';

        if (mb_strlen($term) < 3) {
            // Too short to be a useful search; do not spend a backend call.
            $result = ['drugs' => []];
        } elseif ($digits !== '' && $digits === $term && mb_strlen($digits) >= 9) {
            $result = ['drugs' => $service->searchDrugsByNdc($credential, $term)];
        } else {
            $result = ['drugs' => $service->searchDrugsByName($credential, $term)];
        }

        $respond(200, $result);
    }

    if ($action === 'pharmacy-search') {
        $respond(200, [
            'pharmacies' => $service->searchPharmacies($credential, [
                'organizationName' => $_REQUEST['name'] ?? null,
                'city' => $_REQUEST['city'] ?? null,
                'postalCode' => $_REQUEST['zip'] ?? null,
            ]),
        ]);
    }

    // The inbox's read-only record: cancel answers and requests answered here.
    if ($action === 'pharmacy-updates') {
        $respond(200, ['updates' => array_map(static function (array $r): array {
            $requestType = PharmacyRequestMapper::typeForNotification((string)$r['rx_type']);

            return [
                'notificationUuid' => $r['erx_notification_uuid'],
                'type' => $requestType ?? 'cancel-response',
                'pid' => $r['pid'] === null ? null : (int)$r['pid'],
                'patientName' => (string)$r['patient_name'],
                'clinicianName' => (string)$r['clinician_name'],
                'drugName' => (string)$r['drug_name'],
                // A request answered here shows the decision sent; a cancel
                // answer shows what it did to the Rx.
                'outcome' => $requestType !== null
                    ? strtolower((string)$r['response_code'])
                    : PrescriptionTracker::cancelAnswer($r['rx_status'] === null ? null : (string)$r['rx_status']),
                'detail' => $requestType !== null ? (string)$r['message'] : (string)$r['rx_status_detail'],
                'receivedAt' => (string)$r['created_at'],
                'respondedAt' => (string)$r['responded_at'],
                'respondedBy' => trim($r['responder_fname'] . ' ' . $r['responder_lname']),
                // Cleared once the outcome lookup succeeds.
                'lastError' => (string)$r['last_error'],
            ];
        }, (new NotificationStore())->listRecentUpdates($facilityId))]);
    }

    // Pharmacy-initiated requests. Scoped to the facility rather than a
    // patient: the inbox lists them across patients, and a request can name a
    // patient this install never synced.
    if (str_starts_with($action, 'pharmacy-request')) {
        $notifications = new NotificationStore();

        if ($action === 'pharmacy-requests') {
            // The inbox asks for the whole facility explicitly; otherwise $pid
            // would fall back to whichever chart is open in the session.
            $wholeFacility = ($_REQUEST['scope'] ?? '') === 'facility';
            $rows = $notifications->listActionable($facilityId, $wholeFacility || $pid <= 0 ? null : $pid);
            $respond(200, ['requests' => array_map(static fn(array $r): array => [
                'notificationUuid' => $r['erx_notification_uuid'],
                'type' => PharmacyRequestMapper::typeForNotification((string)$r['rx_type']),
                'pid' => $r['pid'] === null ? null : (int)$r['pid'],
                'patientName' => (string)$r['patient_name'],
                'clinicianName' => (string)$r['clinician_name'],
                'message' => (string)$r['message'],
                'priority' => (string)$r['priority'],
                'requestedAt' => (string)$r['requested_at'],
                'dueAt' => (string)$r['due_at'],
                'state' => (string)$r['state'],
                'responseCode' => (string)$r['response_code'],
                'lastError' => (string)$r['last_error'],
                // No chart here: the review offers to add or link one.
                'needsPatient' => $r['pid'] === null,
            ], $rows)]);
        }

        $notificationUuid = trim((string)($_REQUEST['notificationUuid'] ?? ''));
        $request = $notificationUuid === '' ? null : $notifications->find($notificationUuid);
        // Another facility's request reads as not found, not as forbidden.
        if ($request === null || (int)$request['facility_id'] !== $facilityId) {
            $respond(404, ['error' => xl('Pharmacy request not found.')]);
        }

        $type = PharmacyRequestMapper::typeForNotification((string)$request['rx_type']);
        if ($type === null) {
            $respond(400, ['error' => xl('This notification is not a request that can be answered.')]);
        }

        // A request is addressed to one prescriber. Answering someone else's
        // is refused here rather than left to the backend, so the message can
        // say whose it is.
        $addressedTo = trim((string)$request['erx_clinician_uuid']);
        if (
            $addressedTo !== ''
            && !in_array($addressedTo, [$clinicianUuid, CredentialStore::clinicianPkUuid($credential)], true)
        ) {
            $respond(403, [
                'error' => xl('This request is addressed to') . ' '
                    . ((string)$request['clinician_name'] ?: xl('another prescriber')) . '.',
            ]);
        }

        $detailEndpoints = [
            PharmacyRequestMapper::TYPE_RENEWAL => BackendClient::ENDPOINT_RXRENEWAL_GET,
            PharmacyRequestMapper::TYPE_CHANGE => BackendClient::ENDPOINT_RXCHANGE_GET,
            PharmacyRequestMapper::TYPE_NEW_RX => BackendClient::ENDPOINT_PHARMACY_NEW_RX_GET,
        ];
        $linker = new RequestPatientLinker();

        // The chart a request is linked to, or for one without, the charts
        // that may be the pharmacy's patient. Read from the cached details,
        // so it costs no backend call.
        $patientLink = static function (array $cache) use ($linker, $notifications, $notificationUuid): array {
            $current = $notifications->find($notificationUuid) ?? [];
            $pid = ($current['pid'] ?? null) === null ? null : (int)$current['pid'];
            if ($pid !== null) {
                $chart = $linker->find($pid);
                return ['pid' => $pid] + ($chart === null ? [] : RequestPatientLinker::summary($chart));
            }
            $patient = is_array($cache['patient'] ?? null) ? $cache['patient'] : [];
            return [
                'pid' => null,
                'candidates' => array_map(
                    [RequestPatientLinker::class, 'summary'],
                    $patient === [] ? [] : $linker->candidates($patient)
                ),
            ];
        };

        if ($action === 'pharmacy-request-details') {
            $response = $backend->post(
                $detailEndpoints[$type],
                PrescribingService::requestContext($credential) + ['erxNotificationPkUuid' => $notificationUuid],
                $credential
            );
            $requireSuccess($response);

            // What the prescriber reviewed is what the response is built from.
            $cache = PharmacyRequestMapper::detailCache($type, $response);
            $notifications->saveRequestDetail($notificationUuid, $cache);

            $details = match ($type) {
                PharmacyRequestMapper::TYPE_RENEWAL => PharmacyRequestMapper::renewalDetails($response),
                PharmacyRequestMapper::TYPE_CHANGE => PharmacyRequestMapper::changeDetails($response),
                default => PharmacyRequestMapper::newRxDetails($response),
            };

            if ($type === PharmacyRequestMapper::TYPE_NEW_RX) {
                // The push named no patient; the details do. Recorded so the
                // list shows who it is for, and matched to a chart this
                // install synced, if any. A patient already linked stays.
                $notifications->attachPatient(
                    $notificationUuid,
                    $notifications->resolvePatientId($details['erxPatientUuid'], $facilityId),
                    $details['erxPatientUuid'],
                    $details['patient']['name']
                );
                $details['patientLink'] = $patientLink($cache);
            }

            $respond(200, [
                'state' => (string)$request['state'],
                'lastError' => (string)$request['last_error'],
                'details' => $details,
            ]);
        }

        // Giving a request a chart. Reading charts needs the demographics
        // ACL; creating one or linking needs it with write.
        $chartActions = [
            'pharmacy-request-patient-search',
            'pharmacy-request-link-patient',
            'pharmacy-request-create-patient',
        ];
        if (in_array($action, $chartActions, true)) {
            $write = $action !== 'pharmacy-request-patient-search';
            if (!AclMain::aclCheckCore('patients', 'demo', '', $write ? 'write' : '')) {
                $respond(403, ['error' => xl('You are not authorized to') . ' '
                    . ($write ? xl('add or link patients.') : xl('look up patients.'))]);
            }

            if ($action === 'pharmacy-request-patient-search') {
                $respond(200, ['patients' => array_map(
                    [RequestPatientLinker::class, 'summary'],
                    $linker->search((string)($_REQUEST['term'] ?? ''))
                )]);
            }

            if ($request['pid'] !== null) {
                $respond(409, ['error' => xl('This request is already linked to a chart.')]);
            }
            $cache = $notifications->getRequestDetail($notificationUuid);
            $erxPatientUuid = (string)($cache['erxPatientUuid'] ?? $request['erx_patient_uuid'] ?? '');

            if ($action === 'pharmacy-request-create-patient') {
                $patient = is_array($cache['patient'] ?? null) ? $cache['patient'] : [];
                if ($patient === []) {
                    $respond(409, [
                        'error' => xl('Review the request first;')
                            . ' ' . xl('the pharmacy\'s patient details have not been retrieved.'),
                    ]);
                }
                try {
                    $linkPid = $linker->create($patient);
                } catch (RuntimeException $e) {
                    $respond(400, ['error' => xl('OpenEMR did not accept the patient') . ': ' . $e->getMessage()]);
                }
                $audit('patient-created', 'Chart created from pharmacy request ' . $notificationUuid, $linkPid);
            } else {
                $linkPid = (int)($_POST['linkPid'] ?? 0);
                if ($linkPid <= 0 || $linker->find($linkPid) === null) {
                    $respond(404, ['error' => xl('Patient not found.')]);
                }
            }

            // Guarded on the request still being unlinked; a chart created
            // just now for a request someone else linked meanwhile stays, as
            // a chart, and the message says so.
            if (!$notifications->linkPatient($notificationUuid, $linkPid)) {
                $respond(409, ['error' => xl('This request was linked to a chart by someone else just now.')]);
            }
            $linker->mapErxPatient($linkPid, $facilityId, $erxPatientUuid);
            $audit('patient-linked', 'Pharmacy request ' . $notificationUuid . ' linked to chart', $linkPid);

            $respond(200, ['pid' => $linkPid, 'created' => $action === 'pharmacy-request-create-patient']);
        }

        if ($action === 'pharmacy-request-respond') {
            if (!in_array($request['state'], NotificationStore::AWAITING_RESPONSE, true)) {
                $respond(409, ['error' => xl('This request has already been answered.')]);
            }

            $cache = $notifications->getRequestDetail($notificationUuid);
            $errors = PharmacyRequestMapper::validate($type, $_POST, $cache);
            if ($errors !== []) {
                $respond(400, ['error' => xl('The response is incomplete'), 'errors' => $errors]);
            }

            $context = PrescribingService::requestContext($credential);
            $decision = strtoupper(trim((string)$_POST['decision']));

            // An approved new prescription lands on a chart; without one
            // there is nowhere for it to go, and nothing to track it under.
            if (
                $type === PharmacyRequestMapper::TYPE_NEW_RX
                && $decision === PharmacyRequestMapper::APPROVED
                && $request['pid'] === null
            ) {
                $respond(409, ['error' => xl('Add or link the patient in OpenEMR before approving this request.')]);
            }

            $payload = match ($type) {
                PharmacyRequestMapper::TYPE_RENEWAL
                    => PharmacyRequestMapper::renewalResponse($context, $notificationUuid, $_POST, $cache),
                PharmacyRequestMapper::TYPE_CHANGE
                    => PharmacyRequestMapper::changeResponse($context, $notificationUuid, $_POST, $cache),
                default => PharmacyRequestMapper::newRxResponse(
                    $context,
                    $notificationUuid,
                    $_POST,
                    date_default_timezone_get()
                ),
            };
            $messageType = match ($type) {
                PharmacyRequestMapper::TYPE_RENEWAL => QueueWorker::TYPE_RXRENEWAL_RESPOND,
                PharmacyRequestMapper::TYPE_CHANGE => QueueWorker::TYPE_RXCHANGE_RESPOND,
                default => QueueWorker::TYPE_NEWRX_REQUEST_RESPOND,
            };

            // Claimed before it is queued: of two prescribers answering at
            // once, one gets the request and the other is told it is taken.
            if (!$notifications->markResponseQueued($notificationUuid, $decision, $userId)) {
                $respond(409, ['error' => xl('This request has already been answered.')]);
            }

            try {
                $queued = (new QueueRepository())->enqueueOrRevive(
                    $messageType,
                    $messageType . ':' . $notificationUuid,
                    $payload,
                    $request['pid'] === null ? null : (int)$request['pid'],
                    $facilityId,
                    $userId ?: null
                );
            } catch (Throwable $e) {
                $notifications->markResponseFailed($notificationUuid, 'Could not be queued');
                throw $e;
            }

            $audit(
                match ($type) {
                    PharmacyRequestMapper::TYPE_RENEWAL => 'rxrenewal-response',
                    PharmacyRequestMapper::TYPE_CHANGE => 'rxchange-response',
                    default => 'newrxrequest-response',
                },
                'Response ' . $decision . ' queued for pharmacy request ' . $notificationUuid,
                $request['pid'] === null ? null : (int)$request['pid']
            );

            // Queued, not sent: the worker transmits it. The screen shows the
            // request as on its way until the worker reports back.
            $sendSoon();
            $respond(202, ['queued' => $queued['queued'], 'state' => NotificationStore::STATE_RESPONSE_QUEUED]);
        }

        $respond(400, ['error' => xl('Unknown action') . ': ' . $action]);
    }

    // ID.me two-factor authentication, for the ID.me screen. Works per
    // prescriber registration, not per patient.
    if (str_starts_with($action, 'mfa-')) {
        if ($userId <= 0) {
            $respond(403, ['error' => xl('No user in session.')]);
        }
        $decision = $controlledDecision();

        if ($action === 'mfa-standing') {
            $respond(200, $decisionPayload($decision));
        }

        if (!QueryUtils::existsTable(IdmeMfaSessionStore::TABLE)) {
            $respond(409, [
                'error' => xl('The MFA session table is missing. Disable and re-enable the module to create it.'),
            ]);
        }

        // MFA is for an EPCS prescriber whose identity is VERIFIED. The
        // backend refuses anyone else; saying which here saves a confusing
        // backend error.
        $notEligible = [IdmeMfaService::REASON_NOT_EPCS, IdmeMfaService::REASON_NOT_VERIFIED];
        if (in_array($decision['reason'], $notEligible, true)) {
            $respond(409, ['error' => $reasonMessage($decision['reason'])] + $decisionPayload($decision));
        }

        $mfaService = new IdmeMfaService($backend, new IdmeMfaSessionStore());
        $wasActive = (bool)$decision['mfa']['active'];

        if ($action === 'mfa-methods') {
            $respond(200, ['methods' => $mfaService->listMethods($credential)] + $decisionPayload($decision));
        }

        if ($action === 'mfa-start') {
            $standing = $mfaService->start($credential, $userId, (string)($_POST['methodUuid'] ?? ''));
            // The kind of method only; the label can name a phone number.
            $audit(
                'idme',
                'MFA session started with ' . ($standing['method_name'] ?: 'a method')
                . ' for facility ' . $facilityId,
                null
            );
            $respond(200, $decisionPayload($controlledDecision()));
        }

        if ($action === 'mfa-complete' || $action === 'mfa-check') {
            $standing = $action === 'mfa-complete'
                ? $mfaService->complete($credential, $userId, (string)($_POST['code'] ?? ''))
                : $mfaService->checkDelivery($credential, $userId);
            if ($standing['active'] && !$wasActive) {
                $audit(
                    'idme',
                    'MFA accepted for facility ' . $facilityId . ', valid until ' . $standing['expires_at'],
                    null
                );
            } elseif ($action === 'mfa-complete' && $standing['status'] === IdmeMfaService::STATUS_FAILED) {
                $audit('idme', 'MFA code rejected for facility ' . $facilityId, null);
            }
            $respond(200, $decisionPayload($controlledDecision()));
        }

        $respond(400, ['error' => xl('Unknown action') . ': ' . $action]);
    }

    // Everything below works against a patient.
    if ($pid <= 0) {
        $respond(400, ['error' => xl('No patient in context.')]);
    }

    // Prescriptions already transmitted from here. These act on this
    // install's own record, so they do not need the patient re-synced first.
    if (in_array($action, ['rx-sent-list', 'rx-status', 'rx-cancel', 'rx-fill-indicator'], true)) {
        $tracker = new PrescriptionTracker();

        if ($action === 'rx-sent-list') {
            $respond(200, ['prescriptions' => $withPharmacyNames(array_map(static fn(array $r): array => [
                'medicationUuid' => $r['erx_medication_uuid'],
                'drugName' => (string)$r['drug_name'],
                'status' => (string)$r['status'],
                'transmissionStatus' => (string)$r['transmission_status'],
                'fillStatus' => (string)$r['fill_status'],
                // What the pharmacy was asked to report back, and whether
                // that request got through; absent on scripts sent before
                // the module asked.
                'fillIndicator' => (string)($r['fill_indicator'] ?? ''),
                'fillIndicatorLabel' => ($r['fill_indicator'] ?? '') === ''
                    ? ''
                    : GlobalConfig::fillIndicatorLabel((string)$r['fill_indicator']),
                'fillIndicatorStatus' => (string)($r['fill_indicator_status'] ?? ''),
                'fillIndicatorDetail' => (string)($r['fill_indicator_detail'] ?? ''),
                'fillIndicatorRequestable' => PrescriptionTracker::canRequestFillIndicator($r),
                'detail' => (string)$r['status_detail'],
                'sentAt' => (string)$r['sent_at'],
                'checkedAt' => (string)$r['status_checked_at'],
                'cancellable' => PrescriptionTracker::isCancellable($r),
            ], $tracker->listForPatient($pid, $facilityId)))]);
        }

        $medicationUuid = trim((string)($_POST['medicationUuid'] ?? ''));
        $sent = $medicationUuid === '' ? null : $tracker->find($medicationUuid);
        if ($sent === null || (int)$sent['pid'] !== $pid || (int)$sent['facility_id'] !== $facilityId) {
            $respond(404, ['error' => xl('Prescription not found for this patient.')]);
        }

        if ($action === 'rx-status') {
            $response = $backend->post(
                BackendClient::ENDPOINT_RX_STATUS,
                PrescribingService::requestContext($credential)
                    + ['erxEprescriptionMedicationPKUuid' => $medicationUuid],
                $credential
            );
            $requireSuccess($response);

            $tracker->applyTransmissionStatus(
                $medicationUuid,
                (string)($response['status'] ?? ''),
                trim((string)($response['rxWorkflowTypeRefName'] ?? '') . ' ' . (string)($response['message'] ?? ''))
            );

            $respond(200, [
                'status' => (string)($response['status'] ?? ''),
                'workflow' => (string)($response['rxWorkflowTypeRefName'] ?? ''),
                'message' => (string)($response['message'] ?? ''),
            ]);
        }

        if ($action === 'rx-fill-indicator') {
            if (!PrescriptionTracker::canRequestFillIndicator($sent)) {
                $respond(409, [
                    'error' => xl('Fill reports cannot be requested for this prescription in its current state.'),
                ]);
            }
            $result = $queueFillIndicator($medicationUuid, $tracker);
            if ($result === null) {
                $respond(409, [
                    'error' => xl('Fill reports are switched off.') . ' '
                        . xl('Choose an option under Miscellaneous, LogiCoy eRx, Settings, Prescribing.'),
                ]);
            }
            $respond(202, ['queued' => $result['queued'], 'indicator' => $result['indicator']]);
        }

        // rx-cancel
        if (!PrescriptionTracker::isCancellable($sent)) {
            $respond(409, ['error' => xl('This prescription cannot be cancelled in its current state.')]);
        }

        // Keyed on the medication: a double click, or a second prescriber
        // pressing cancel, queues one RxCancel. A cancel the backend refused
        // can be sent again.
        $queued = (new QueueRepository())->enqueueOrRevive(
            QueueWorker::TYPE_RXCANCEL,
            QueueWorker::TYPE_RXCANCEL . ':' . $medicationUuid,
            PrescribingService::requestContext($credential) + ['erxEprescriptionMedicationPKUuid' => $medicationUuid],
            $pid,
            $facilityId,
            $userId ?: null
        );

        if ($queued['queued']) {
            $tracker->markCancelQueued($medicationUuid);
            $audit('rxcancel', 'RxCancel queued for medication ' . $medicationUuid, $pid);
        }

        $sendSoon();
        $respond(202, ['queued' => $queued['queued']]);
    }

    // Everything below needs the patient to exist on the eRx side first.
    // ensure() is idempotent — a repeat call updates, never duplicates.
    $sync = new PatientSync($backend);

    if ($action === 'patient-sync') {
        $respond(200, ['erxPatientPKUuid' => $sync->ensure($pid, $facilityId, $credential)]);
    }

    $erxPatientPKUuid = $sync->ensure($pid, $facilityId, $credential);

    // The chart's Prescriptions card reads OpenEMR's own prescriptions
    // table, so every medication this screen creates, changes, removes or
    // sends is mirrored there, keyed on the LogiCoy medication uuid. The
    // backend call has already succeeded by the time this runs, so a chart
    // write that fails is told to the prescriber as a warning on an
    // otherwise successful answer, never as an error that reads as "not
    // saved" or "not sent".
    //
    // Returns the fields to merge into the response: chart (off | written |
    // failed) and warning.
    $chart = static function (string $what, string $uuid, array $medication = []) use ($config, $pid, $userId): array {
        if (!$config->shouldWriteToPrescriptions() || $uuid === '') {
            return ['chart' => 'off', 'warning' => ''];
        }
        try {
            $writer = new PrescriptionWriter();
            if ($what === 'remove') {
                $writer->deactivate($uuid, $pid);
            } else {
                $medication += ['providerUserId' => $userId, 'encounter' => SessionContext::getInt('encounter')];
                $medication['drugName'] = (string)($medication['drugName'] ?? '')
                    ?: xl('Prescription written through LogiCoy eRx');
                if ($what === 'update') {
                    $writer->update($uuid, $pid, $medication);
                } else {
                    $writer->reflect($uuid, $pid, $medication);
                }
            }

            return ['chart' => 'written', 'warning' => ''];
        } catch (Throwable $e) {
            (new SystemLogger())->error('LogiCoy prescription ' . $what . ' not written to the chart', [
                'medication' => $uuid,
                'error' => $e->getMessage(),
            ]);

            return [
                'chart' => 'failed',
                'warning' => xl('Saved with LogiCoy, but it could not be written to the patient\'s prescription list.')
                    . ' ' . xl('Check the LogiCoy eRx log.'),
            ];
        }
    };

    // The prescribing form, in the writer's shape.
    $formMedication = static fn(array $form): array => [
        'drugName' => (string)($form['drugName'] ?? ''),
        'ndcNumber' => (string)($form['ndcNumber'] ?? ''),
        'sig' => (string)($form['sig'] ?? ''),
        'quantity' => (string)($form['quantity'] ?? ''),
        'refills' => (string)($form['refills'] ?? ''),
        'effectiveDate' => (string)($form['effectiveDate'] ?? ''),
        'substitutionAllowed' => DraftMapper::substitutionAllowed($form),
    ];

    // The patient's drafts on the eRx side, as the screen lists them.
    $listDrafts = static function () use ($backend, $credential, $clinicianUuid, $erxPatientPKUuid): array {
        $response = $backend->post(BackendClient::ENDPOINT_DRAFT_LIST, [
            'transactionUuid' => PrescribingService::newTransactionId(),
            'erxPracticePKUuid' => $credential['erx_practice_uuid'] ?? '',
            'erxPracticeServiceLocPKUuid' => $credential['erx_location_uuid'] ?? '',
            'erxClinicianUserUuid' => $clinicianUuid,
            'erxPatientPKUuid' => $erxPatientPKUuid,
        ], $credential);

        // A rejected list call must not read as "No drafts yet".
        $failure = BackendClient::reportedFailure($response);
        if ($failure !== null) {
            throw new BackendException($failure, 422, ['endpoint' => BackendClient::ENDPOINT_DRAFT_LIST]);
        }

        return DraftMapper::extract($response);
    };

    if ($action === 'draft-list') {
        $respond(200, ['drafts' => $withPharmacyNames($listDrafts())]);
    }

    if ($action === 'draft-add') {
        // A controlled substance may be drafted once the prescriber is EPCS
        // and VERIFIED; MFA is checked when the draft is sent.
        $reason = $controlledDecision()['reason'];
        $mayDraftControlled = !in_array(
            $reason,
            [IdmeMfaService::REASON_NOT_EPCS, IdmeMfaService::REASON_NOT_VERIFIED],
            true
        );
        $errors = DraftMapper::validate($_POST, $mayDraftControlled);
        if ($errors !== []) {
            $respond(400, ['error' => xl('Prescription is incomplete'), 'errors' => $errors]);
        }

        $response = $backend->post(BackendClient::ENDPOINT_DRAFT_ADD, [
            'transactionUuid' => PrescribingService::newTransactionId(),
            'erxPracticePKUuid' => $credential['erx_practice_uuid'] ?? '',
            'erxPracticeServiceLocPKUuid' => $credential['erx_location_uuid'] ?? '',
            'erxClinicianUserUuid' => $clinicianUuid,
            'erxPatientPKUuid' => $erxPatientPKUuid,
            'selectedPharmacyOrgId' => (string)($_POST['pharmacyOrgId'] ?? ''),
            'eprescriptionMedicationList' => [DraftMapper::toMedication($_POST)],
            'timeZone' => date_default_timezone_get(),
        ], $credential);

        $failure = DraftMapper::addFailure($response);
        if ($failure !== null) {
            throw new BackendException($failure, 422, ['endpoint' => BackendClient::ENDPOINT_DRAFT_ADD]);
        }

        $newUuid = (string)BackendClient::findValue($response, 'erxEprescriptionMedicationPKUuid');
        $rememberPharmacy($newUuid, $_POST);

        $respond(200, ['medicationUuid' => $newUuid] + $chart('add', $newUuid, $formMedication($_POST)));
    }

    $medicationUuid = (string)($_POST['medicationUuid'] ?? '');
    if ($medicationUuid === '') {
        $respond(400, ['error' => xl('No prescription selected.')]);
    }

    if ($action === 'draft-update') {
        // Same rules as a new draft, except the pharmacy may be left as it
        // was. The medication keeps its uuid, which is how the backend knows
        // which draft to change; a draft already sent is refused by it.
        $reason = $controlledDecision()['reason'];
        $mayDraftControlled = !in_array(
            $reason,
            [IdmeMfaService::REASON_NOT_EPCS, IdmeMfaService::REASON_NOT_VERIFIED],
            true
        );
        $errors = DraftMapper::validate($_POST, $mayDraftControlled, true);
        if ($errors !== []) {
            $respond(400, ['error' => xl('Prescription is incomplete'), 'errors' => $errors]);
        }

        $payload = [
            'transactionUuid' => PrescribingService::newTransactionId(),
            'erxPracticePKUuid' => $credential['erx_practice_uuid'] ?? '',
            'erxPracticeServiceLocPKUuid' => $credential['erx_location_uuid'] ?? '',
            'erxClinicianUserUuid' => $clinicianUuid,
            'erxPatientPKUuid' => $erxPatientPKUuid,
            'eprescriptionMedicationList' => [DraftMapper::toMedication($_POST)],
        ];
        if (!empty($_POST['pharmacyOrgId'])) {
            $payload['selectedPharmacyOrgId'] = (string)$_POST['pharmacyOrgId'];
        }
        $response = $backend->post(BackendClient::ENDPOINT_DRAFT_UPDATE, $payload, $credential);

        $failure = DraftMapper::addFailure($response);
        if ($failure !== null) {
            throw new BackendException($failure, 422, ['endpoint' => BackendClient::ENDPOINT_DRAFT_UPDATE]);
        }
        // Left blank, the draft keeps its pharmacy, and so does this record.
        if (!empty($_POST['pharmacyOrgId'])) {
            $rememberPharmacy($medicationUuid, $_POST);
        }

        $respond(
            200,
            ['medicationUuid' => $medicationUuid] + $chart('update', $medicationUuid, $formMedication($_POST))
        );
    }

    if ($action === 'draft-remove') {
        $backend->post(BackendClient::ENDPOINT_DRAFT_REMOVE, [
            'transactionUuid' => PrescribingService::newTransactionId(),
            'erxPracticePKUuid' => $credential['erx_practice_uuid'] ?? '',
            'erxPracticeServiceLocPKUuid' => $credential['erx_location_uuid'] ?? '',
            'erxClinicianUserUuid' => $clinicianUuid,
            'eprescriptionMedicationList' => [['erxEprescriptionMedicationPKUuid' => $medicationUuid]],
        ], $credential);

        $respond(200, ['removed' => $medicationUuid] + $chart('remove', $medicationUuid));
    }

    if ($action === 'rx-send') {
        // The draft as the backend holds it. The controlled-substance gate
        // below needs its NDC, and the chart needs its sig and quantities
        // once it is sent. A listing that fails is not by itself a reason to
        // refuse the send: the backend decides that when asked to send.
        $draft = null;
        $listFailure = null;
        try {
            foreach ($listDrafts() as $d) {
                if ($d['medicationUuid'] === $medicationUuid) {
                    $draft = $d;
                    break;
                }
            }
        } catch (BackendException $e) {
            $listFailure = $e;
        }

        // A controlled substance needs an EPCS prescriber who is VERIFIED with
        // ID.me and has passed MFA this session. The backend checks the same
        // on send; refusing here says why, before the call. Drafts come back
        // without their schedule, so only when the prescriber is not
        // authorised is the draft's drug looked up to see whether it matters.
        $decision = $controlledDecision();
        if (!$decision['allowed']) {
            if ($draft === null) {
                if ($listFailure !== null) {
                    throw $listFailure;
                }
                $respond(404, ['error' => xl('Draft not found. Refresh the drafts list.')]);
            }

            // Null when the backend returns nothing for the NDC. A drug that
            // cannot be classified is refused like a controlled one: sending
            // it blind would hand the decision to a backend error message.
            $controlled = $service->isControlledNdc($credential, (string)$draft['ndcNumber']);
            if ($controlled !== false) {
                $audit(
                    'rxnew-refused',
                    'RxNew refused for medication ' . $medicationUuid . ': '
                    . ($controlled === null ? 'schedule unknown' : 'controlled substance')
                    . ', ' . $decision['reason'],
                    $pid
                );
                $respond(403, [
                    'error' => $controlled === null
                        ? xl('LogiCoy could not confirm whether this drug is a controlled substance,')
                            . ' ' . xl('and you are not currently authorised to send one.')
                        : xl('This is a controlled substance.') . ' ' . $reasonMessage($decision['reason']),
                    'detail' => $decision['reason'] === IdmeMfaService::REASON_NOT_EPCS
                        ? ''
                        : xl('Open Miscellaneous, LogiCoy eRx, ID.me Verification to complete it.'),
                    'reason' => $decision['reason'],
                    'controlled' => $controlled,
                ]);
            }
        }

        $response = $backend->post(BackendClient::ENDPOINT_RXNEW_SEND, [
            'transactionUuid' => PrescribingService::newTransactionId(),
            'erxPracticePKUuid' => $credential['erx_practice_uuid'] ?? '',
            'erxPracticeServiceLocPKUuid' => $credential['erx_location_uuid'] ?? '',
            'erxClinicianUserPKUuid' => CredentialStore::clinicianPkUuid($credential),
            'erxEprescriptionMedicationPKUuid' => $medicationUuid,
            'timeZone' => date_default_timezone_get(),
        ], $credential);
        $requireSuccess($response);

        // Recorded so it can be cancelled and tracked from this screen, and
        // so inbound cancel and fill results have a row to land on.
        $transactionStatus = is_array($response['transactionStatus'] ?? null) ? $response['transactionStatus'] : [];
        $tracker = new PrescriptionTracker();
        $tracker->recordSent(
            $medicationUuid,
            $pid,
            $facilityId,
            $userId ?: null,
            (string)($draft['drugName'] ?? '') ?: (string)($_POST['drugName'] ?? ''),
            (string)($transactionStatus['status'] ?? '')
        );

        // Fill reports are no longer requested automatically on send. The
        // prescriber asks for them explicitly with the "Request fill reports"
        // button (the rx-fill-indicator action), which shares the same queue
        // path. The sent card shows "Fill reports: not requested" until then.

        // Already on the chart since the draft was saved; this brings the
        // row up to date with what was actually sent, or writes it when the
        // draft predates the chart mirror. Keyed on the medication uuid, so
        // a retried send finds the row it already has.
        $sent = $draft === null
            ? ['drugName' => (string)($_POST['drugName'] ?? '')]
            : [
                'drugName' => (string)($draft['drugName'] ?? '') ?: (string)($_POST['drugName'] ?? ''),
                'ndcNumber' => (string)($draft['ndcNumber'] ?? ''),
                'sig' => (string)($draft['sig'] ?? ''),
                'quantity' => (string)($draft['quantity'] ?? ''),
                'refills' => (string)($draft['refills'] ?? ''),
                'effectiveDate' => (string)($draft['effectiveDate'] ?? ''),
                'substitutionAllowed' => $draft['substitutionAllowed'] ?? null,
            ];
        $chartResult = $chart($draft === null ? 'add' : 'update', $medicationUuid, $sent);

        // When the prescriber had no MFA session, the send went ahead only
        // because the NDC lookup said the drug is not controlled; the trail
        // records that, so a scheduled drug that slipped through can be
        // traced to the lookup rather than the gate.
        $audit(
            'rxnew',
            'RxNew sent for medication ' . $medicationUuid
            . ($decision['allowed'] ? '' : ' (not controlled per NDC lookup; no MFA session)'),
            $pid
        );

        $respond(200, [
            'ok' => true,
            'response' => $response,
        ] + $chartResult);
    }

    $respond(400, ['error' => xl('Unknown action') . ': ' . $action]);
} catch (BackendException $e) {
    (new SystemLogger())->error('LogiCoy eRx API call failed', [
        'action' => $action,
        'status' => $e->getHttpStatus(),
        'error' => $e->getMessage(),
    ]);
    // The screen gets what the backend said; the endpoint and HTTP status
    // are in the log line above for whoever is troubleshooting.
    $respond(502, [
        'error' => $e->getUserMessage(),
        'code' => (string)($e->getContext()['backend_error'] ?? ''),
    ]);
} catch (Throwable $e) {
    (new SystemLogger())->error('LogiCoy eRx API error', ['action' => $action, 'error' => $e->getMessage()]);
    $respond(500, ['error' => xl('Unexpected error. Check the LogiCoy eRx log.')]);
}
