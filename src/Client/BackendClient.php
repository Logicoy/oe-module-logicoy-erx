<?php

/**
 * HTTP client for the LogiCoy eRx backend.
 *
 * Every clinical decision — drug lookup, interaction checking, SCRIPT
 * construction, EPCS signing, Surescripts routing — happens behind this
 * boundary. This class only moves JSON.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Client;

use LogiCoy\ERx\Config\CredentialStore;
use LogiCoy\ERx\Config\GlobalConfig;
use OpenEMR\Common\Logging\SystemLogger;

class BackendClient
{
    // Onboarding. The backend mints the practice, location and clinician uuids;
    // they are never entered by hand.
    public const ENDPOINT_PRACTICE_ADD = '/v1/practice/add';
    public const ENDPOINT_LOCATION_ADD = '/v1/practice-service-location/add';
    public const ENDPOINT_CLINICIAN_ADD = '/v1/clinician/add';
    // Partial update of a registered clinician: DEA details, service levels,
    // contact details. Name and prescribing type cannot be changed through it.
    public const ENDPOINT_CLINICIAN_UPDATE = '/v1/clinician/update';
    // The ERX -> EPCS flip the update endpoint cannot do: validates the DEA,
    // assigns EPCS roles, registers the prescriber with Surescripts at
    // controlled substance service levels, and starts ID.me proofing in the
    // same call. Irreversible. In the staging spec only, as of 2026-10-17.
    public const ENDPOINT_CLINICIAN_UPGRADE_EPCS = '/v1/clinician/upgrade-to-epcs';
    public const ENDPOINT_CLINICIAN_UPGRADE_EPCS_STATUS = '/v1/clinician/upgrade-to-epcs/status';

    // Prescriber identity proofing. initiate returns a link the prescriber
    // opens in their own browser; ID.me does the proofing and redirects back,
    // and check-status reports the outcome and when it expires.
    public const ENDPOINT_IDME_INITIATE = '/v1/idme/verify-with-idme/initiate';
    public const ENDPOINT_IDME_STATUS = '/v1/idme/verify-with-idme/check-status';

    // Per-session second factor for a VERIFIED EPCS prescriber. mfa-details
    // lists the methods on the prescriber's ID.me account; start opens a
    // session with one of them; complete takes the code for Authenticator,
    // SMS and Email; delivery-status-check is polled for Push. An accepted
    // session authorises controlled substance sends until its expires time.
    public const ENDPOINT_IDME_MFA_METHODS = '/v1/idme/mfa/initiate-to-fetch-mfa-details';
    public const ENDPOINT_IDME_MFA_START = '/v1/idme/mfa/start';
    public const ENDPOINT_IDME_MFA_COMPLETE = '/v1/idme/mfa/complete';
    public const ENDPOINT_IDME_MFA_DELIVERY_STATUS = '/v1/idme/mfa/delivery-status-check';

    public const ENDPOINT_PATIENT_ADD = '/v1/patient/add';
    public const ENDPOINT_PATIENT_UPDATE = '/v1/patient/update';
    public const ENDPOINT_DRAFT_ADD = '/v1/prescription/draft/add';
    public const ENDPOINT_DRAFT_LIST = '/v1/prescription/draft/list';
    public const ENDPOINT_DRAFT_UPDATE = '/v1/prescription/draft/update';
    public const ENDPOINT_DRAFT_REMOVE = '/v1/prescription/draft/remove';
    public const ENDPOINT_RXNEW_SEND = '/v1/prescription/rxnew/send';
    public const ENDPOINT_RXCANCEL = '/v1/prescription/rxcancel/request';
    // Tells the pharmacy which dispensing events (RxFill) to report back for a
    // prescription that has already been sent. The clinician needs the
    // RX_FILL_INDICATOR_CHANGE service level; the code set is listed in
    // GlobalConfig::FILL_INDICATORS.
    public const ENDPOINT_RXFILL_INDICATOR_CHANGE = '/v1/prescription/rx-change-fill-indicator';

    // Read-only status lookups for a transmitted prescription. rx-status is
    // keyed on the medication; the other three on the notification that
    // announced the result.
    public const ENDPOINT_RX_STATUS = '/v1/prescription/rx-status';
    public const ENDPOINT_RXCANCEL_STATUS = '/v1/prescription/rx-cancel/check-status';
    public const ENDPOINT_RXFILL_STATUS = '/v1/prescription/rx-fill/check-status';
    public const ENDPOINT_RXNEW_VERIFICATION_STATUS = '/v1/prescription/rxnew/get-pending-verification-status';
    // Inbound. These are the real endpoints, taken from the LogiCoy SDK — an
    // earlier guess at /v1/prescription/inbound/* was wrong.
    public const ENDPOINT_NOTIFICATIONS = '/v1/notifications/get';
    public const ENDPOINT_RXRENEWAL_GET = '/v1/rxrenewal/get-details';
    public const ENDPOINT_RXRENEWAL_RESPOND = '/v1/rxrenewal/send-response';
    public const ENDPOINT_RXCHANGE_GET = '/v1/rxchange/get-details';
    public const ENDPOINT_RXCHANGE_RESPOND = '/v1/rxchange/send-response';
    // A pharmacy asking for a new prescription on a patient's behalf. In the
    // staging spec only, as of 2026-10-26. get-details also creates the
    // patient on the eRx side when the pharmacy's patient is unknown there.
    public const ENDPOINT_PHARMACY_NEW_RX_GET = '/v1/pharmacy-initiated/new-rx/get-details';
    public const ENDPOINT_PHARMACY_NEW_RX_RESPOND = '/v1/pharmacy-initiated/new-rx/send-response';
    public const ENDPOINT_RXHISTORY_GET = '/v1/rxhistory/get/details';
    public const ENDPOINT_RXHISTORY_REQUEST = '/v1/rxhistory/send/request';

    // Lookups that back the prescribing screen. Drug and pharmacy data are
    // resolved by the backend, never held or searched in PHP.
    public const ENDPOINT_DRUG_SEARCH_BY_NAME = '/v1/drug/details/search/by/productname';
    public const ENDPOINT_DRUG_SEARCH_BY_NDC = '/v1/drug/details/search/by/ndc';
    public const ENDPOINT_PHARMACY_SEARCH = '/v1/pharmacy/details/search';

    private GlobalConfig $config;
    private CredentialStore $credentials;
    private TokenProvider $tokenProvider;
    private SystemLogger $logger;

    public function __construct(
        GlobalConfig $config,
        CredentialStore $credentials,
        ?TokenProvider $tokenProvider = null,
        ?SystemLogger $logger = null
    ) {
        $this->config = $config;
        $this->credentials = $credentials;
        $this->tokenProvider = $tokenProvider ?? new TokenProvider($config, $credentials);
        $this->logger = $logger ?? new SystemLogger();
    }

    /**
     * POST a JSON payload to the backend on behalf of a practice.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $credential
     * @return array<string, mixed>
     */
    public function post(string $endpoint, array $payload, array $credential): array
    {
        $baseUrl = $this->config->getBackendBaseUrl();
        if ($baseUrl === '') {
            throw new BackendException('LogiCoy backend base URL is not configured');
        }

        $token = $this->tokenProvider->getAccessToken($credential);
        $response = $this->send($baseUrl . $endpoint, $payload, $token);

        // A rejected token is usually an expiry we did not predict; refresh once.
        if ($response['status'] === 401 || $response['status'] === 403) {
            $this->tokenProvider->invalidate((int)$credential['id']);
            $token = $this->tokenProvider->getAccessToken($credential, true);
            $response = $this->send($baseUrl . $endpoint, $payload, $token);
        }

        if ($response['status'] >= 400) {
            throw self::failure($endpoint, $response);
        }

        return $response['body'];
    }

    /**
     * The exception for a 4xx or 5xx answer.
     *
     * The log message names the endpoint and HTTP status. The user message
     * is what the backend itself said — "The medication contains a controlled
     * substance ... contact LogiCoy eRx support" — because that, not the
     * endpoint path, is what the prescriber can act on. The backend's error
     * code rides along in brackets so support can look it up.
     *
     * A 5xx is the backend failing to complete a request it accepted. Saying
     * it "rejected" the call sends whoever is troubleshooting off checking
     * credentials and payloads when nothing on this side is wrong.
     *
     * @param array{status: int, body: array<string, mixed>, error_code: string, error_detail: string} $response
     */
    private static function failure(string $endpoint, array $response): BackendException
    {
        $status = (int)$response['status'];
        $serverSide = $status >= 500;

        $said = self::explain($response['body']);
        if ($serverSide) {
            $log = 'LogiCoy backend could not complete ' . $endpoint . ' (HTTP ' . $status . ')'
                . rtrim($response['error_detail'], '.')
                . '. This is a failure inside the LogiCoy backend, not a problem with the credential.';
            $user = $said !== ''
                ? $said
                : 'The LogiCoy backend could not complete the request (HTTP ' . $status . ').';
            $user .= ' This is a failure inside the LogiCoy backend, not a problem with the credential.';
        } else {
            $log = 'LogiCoy backend rejected ' . $endpoint . ' (HTTP ' . $status . ')' . $response['error_detail'];
            $user = $said !== '' ? $said : 'The LogiCoy backend rejected the request (HTTP ' . $status . ').';
        }

        return new BackendException(
            $log,
            $status,
            ['endpoint' => $endpoint, 'backend_error' => $response['error_code']],
            null,
            $user
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, body: array<string, mixed>, error_code: string, error_detail: string}
     */
    private function send(string $url, array $payload, string $token): array
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config->getHttpTimeout(),
            CURLOPT_USERAGENT => self::userAgent(),
        ]);

        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);

        if ($body === false) {
            throw new BackendException(
                'LogiCoy backend call failed: ' . $curlError,
                0,
                [],
                null,
                'Could not reach the LogiCoy backend: ' . $curlError
            );
        }

        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        if ($this->config->isDebugLoggingEnabled()) {
            // Metadata only. The payload carries prescription content and PHI.
            $this->logger->debug('LogiCoy backend call', [
                'url' => $url,
                'status' => $status,
                'response_bytes' => strlen((string)$body),
            ]);
        }

        return [
            'status' => $status,
            'body' => $decoded,
            'error_code' => self::errorCode($decoded),
            'error_detail' => self::describeError($decoded),
        ];
    }

    /**
     * PHP's curl sends no User-Agent unless told to, and the AWS WAF in front
     * of the LogiCoy backend answers any request without one with a bare
     * nginx/ELB "403 Forbidden" — token endpoint included. That reads as a
     * rejected credential when the request never reached LogiCoy at all.
     */
    public static function userAgent(): string
    {
        static $agent = null;
        if ($agent === null) {
            $version = (static function (): string {
                $v_major = $v_minor = $v_patch = '';
                include __DIR__ . '/../../version.php';
                return $v_major . '.' . $v_minor . '.' . $v_patch;
            })();
            $agent = 'LogiCoy-eRx-OpenEMR/' . $version;
        }

        return $agent;
    }

    /**
     * The backend's own code for a failure.
     *
     * This API answers every eRx endpoint with a transactionStatus envelope and
     * puts the code inside it; only the Spring level errors — an unroutable or
     * malformed request — put anything at the top level. Reading the top level
     * alone left every failure with an empty code.
     *
     * @param array<string, mixed> $decoded
     */
    public static function errorCode(array $decoded): string
    {
        $status = is_array($decoded['transactionStatus'] ?? null) ? $decoded['transactionStatus'] : [];

        return (string)(
            $status['errorCode']
            ?? $decoded['errorCode']
            ?? $decoded['error']
            ?? ''
        );
    }

    /**
     * What the backend said went wrong, for the message the prescriber sees.
     *
     * Without this a failure reads only as "the backend rejected the request",
     * which is what sent this module's first support case looking at tokens
     * while the backend was answering "Failed to search medications" all along.
     *
     * Only the status envelope's own fields are taken, never the whole body:
     * a response can echo the request back, and that carries prescription
     * content.
     *
     * @param array<string, mixed> $decoded
     */
    public static function describeError(array $decoded): string
    {
        $parts = [];
        foreach ([self::errorCode($decoded), self::statusMessage($decoded)] as $value) {
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        $reasons = self::reasons($decoded);
        if ($reasons !== []) {
            $parts[] = implode('; ', $reasons);
        }

        return $parts === [] ? '' : ': ' . implode(' — ', $parts);
    }

    /**
     * The backend's own words for the screen: its status message, then any
     * field-level reasons, then its error code in brackets for support.
     *
     * A code-only answer still names the code — "LogiCoy error GEN_ERC_1001"
     * is something support can look up, where a blank is not. Returns '' when
     * the response carries no error fields at all (an HTML page from a
     * firewall, an empty body).
     *
     * @param array<string, mixed> $decoded
     */
    public static function explain(array $decoded): string
    {
        $parts = [];
        $message = self::statusMessage($decoded);
        if ($message !== '') {
            $parts[] = $message;
        }
        $reasons = self::reasons($decoded);
        if ($reasons !== []) {
            $parts[] = implode('; ', $reasons);
        }
        $text = implode(' — ', $parts);

        // Only a machine code is worth quoting. The framework's "Bad Request"
        // in the error field says nothing the status line did not.
        $code = self::errorCode($decoded);
        if ($code !== '' && preg_match('/^[A-Z][A-Z0-9_]*_[A-Z0-9_]*\d[A-Z0-9_]*$/', $code) === 1) {
            $text = $text === '' ? 'LogiCoy error ' . $code : $text . ' (' . $code . ')';
        }

        return $text;
    }

    /**
     * The backend's sentence about the failure, from its status envelope or
     * the framework's message field; never the rest of the body.
     *
     * @param array<string, mixed> $decoded
     */
    public static function statusMessage(array $decoded): string
    {
        $status = is_array($decoded['transactionStatus'] ?? null) ? $decoded['transactionStatus'] : [];

        $candidates = [
            $status['statusMessage'] ?? null,
            $status['errorMessage'] ?? null,
            $status['statusDescription'] ?? null,
            $decoded['message'] ?? null,
            $decoded['errorMessage'] ?? null,
            $decoded['error_description'] ?? null,
            $decoded['detail'] ?? null,
        ];
        foreach ($candidates as $value) {
            if (is_scalar($value) && trim((string)$value) !== '') {
                return mb_substr(trim((string)$value), 0, 500);
            }
        }

        return '';
    }

    /**
     * The field-level reasons a request was refused, each as "field: reason"
     * unless the reason already names the field.
     *
     * The eRx endpoints list them in validationErrorInfo as variableName and
     * errorMessage; the framework's own validation uses errors or fieldErrors
     * with field and defaultMessage. Both are read, and nothing else: the
     * reasons are the backend's words about the request, not the request.
     *
     * @param array<string, mixed> $decoded
     * @return list<string>
     */
    public static function reasons(array $decoded): array
    {
        $reasons = [];
        $add = static function (mixed $field, mixed $message) use (&$reasons): void {
            $message = is_scalar($message) ? mb_substr(trim((string)$message), 0, 300) : '';
            $field = is_scalar($field) ? self::fieldLabel((string)$field) : '';
            if ($message === '') {
                return;
            }
            if ($field !== '' && stripos($message, $field) === false) {
                $message = $field . ': ' . $message;
            }
            if (!in_array($message, $reasons, true)) {
                $reasons[] = $message;
            }
        };

        foreach (['validationErrorInfo', 'errors', 'fieldErrors', 'details'] as $key) {
            $list = $decoded[$key] ?? null;
            if (is_string($list)) {
                $list = [$list];
            }
            foreach (is_array($list) ? $list : [] as $info) {
                if (is_array($info)) {
                    $add(
                        $info['variableName'] ?? $info['field'] ?? $info['fieldName'] ?? '',
                        $info['errorMessage'] ?? $info['defaultMessage'] ?? $info['message'] ?? ''
                    );
                } else {
                    $add('', $info);
                }
            }
        }

        return array_slice($reasons, 0, 10);
    }

    /**
     * The eRx API's request field names, as the prescribing screen labels
     * them. A reason the backend reports against
     * "eprescriptionMedicationList[0].noOfUnits" is about the Quantity box.
     */
    private const FIELD_LABELS = [
        'noOfUnits' => 'Quantity',
        'daysSupply' => 'Days supply',
        'refillCount' => 'Refills',
        'sig' => 'Directions',
        'ndcNumber' => 'NDC number',
        'drugName' => 'Drug',
        'effectiveDate' => 'Start date',
        'notesToPharmacist' => 'Notes to pharmacist',
        'isSubstitutionAllowed' => 'Substitution allowed',
        'pharmacyOrgId' => 'Pharmacy',
        'erxPharmacyPKUuid' => 'Pharmacy',
    ];

    /**
     * The screen's label for a backend field path, or the path's own last
     * segment for a field the screen has no label for.
     */
    public static function fieldLabel(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        $leaf = (string)preg_replace('/\[\d+\]/', '', substr($path, (int)strrpos('.' . $path, '.')));

        return self::FIELD_LABELS[$leaf] ?? ($leaf !== '' ? $leaf : $path);
    }

    /**
     * A failure the backend reported inside an HTTP 200.
     *
     * The eRx endpoints validate after accepting the request and answer 200
     * with isValidationError set and the reasons in validationErrorInfo. Taking
     * that as success would tell a prescriber a cancel or a refill response
     * reached the pharmacy when nothing was sent.
     *
     * Returns null when the response is not a failure. Only the error fields
     * are read, never the rest of the body, which can echo prescription content.
     *
     * @param array<string, mixed> $decoded
     */
    public static function reportedFailure(array $decoded): ?string
    {
        $status = is_array($decoded['transactionStatus'] ?? null) ? $decoded['transactionStatus'] : [];
        $isValidationError = ($decoded['isValidationError'] ?? false) === true;
        $isServerError = ($status['serverError'] ?? false) === true;

        if (!$isValidationError && !$isServerError) {
            return null;
        }

        $detail = self::explain($decoded);

        return $detail === '' ? 'The LogiCoy backend reported a failure' : $detail;
    }

    /**
     * Walk a backend response for the first value of a key. The gateway nests
     * its identifiers at different depths depending on the endpoint.
     *
     * @param mixed $node
     */
    public static function findValue($node, string $key): ?string
    {
        if (!is_array($node)) {
            return null;
        }

        if (isset($node[$key]) && is_string($node[$key]) && $node[$key] !== '') {
            return $node[$key];
        }

        foreach ($node as $value) {
            $found = self::findValue($value, $key);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
