<?php

/**
 * ID.me multi-factor authentication for a VERIFIED EPCS prescriber.
 *
 * Identity proofing (IdmeVerificationService) is done once and lasts until
 * its expiry date. MFA is the second factor the prescriber passes per session
 * before LogiCoy will accept a controlled substance prescription from them,
 * valid until the expires time the backend returns.
 *
 * The flow is the backend's: list the methods on the prescriber's ID.me
 * account, start a session with one, then either submit the code ID.me
 * delivered (Authenticator, SMS, Email) or poll until the push notification
 * is accepted. ID.me checks the factor; this class only carries the answer
 * and remembers whether the prescriber passed, and until when. Nothing here
 * signs anything: the backend applies its own MFA check on rxnew/send, and
 * this module's gate exists so a prescriber is told clearly beforehand.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Identity;

use DateTimeImmutable;
use DateTimeZone;
use LogiCoy\ERx\Client\BackendClient;
use LogiCoy\ERx\Client\BackendException;
use LogiCoy\ERx\Config\CredentialStore;
use LogiCoy\ERx\Prescription\PrescribingService;
use Throwable;

class IdmeMfaService
{
    // Normalised from the backend's status word. Only ACCEPTED ever unlocks
    // a send; an unknown word is stored as FAILED.
    public const STATUS_WAITING = 'WAITING';
    public const STATUS_ACCEPTED = 'ACCEPTED';
    public const STATUS_FAILED = 'FAILED';
    /** Ours: a stored session past its expires time, or with none. */
    public const STATUS_EXPIRED = 'EXPIRED';
    /** Ours: no session stored. */
    public const STATUS_NONE = 'NONE';

    // Why a controlled substance send is refused. The screens turn these
    // into translated messages; the rule itself stays testable without xl().
    public const REASON_NOT_EPCS = 'not_epcs';
    public const REASON_NOT_VERIFIED = 'not_verified';
    public const REASON_MFA_REQUIRED = 'mfa_required';
    public const REASON_MFA_EXPIRED = 'mfa_expired';

    private BackendClient $backend;
    private IdmeMfaSessionStoreInterface $store;

    public function __construct(BackendClient $backend, IdmeMfaSessionStoreInterface $store)
    {
        $this->backend = $backend;
        $this->store = $store;
    }

    /**
     * The MFA methods on the prescriber's ID.me account.
     *
     * @param array<string, mixed> $credential the prescriber's own credential row
     * @return array<int, array{uuid: string, name: string, option: string, label: string, isPush: bool}>
     */
    public function listMethods(array $credential): array
    {
        $response = $this->backend->post(
            BackendClient::ENDPOINT_IDME_MFA_METHODS,
            $this->identify($credential),
            $credential
        );

        $failure = BackendClient::reportedFailure($response);
        if ($failure !== null) {
            throw new BackendException('LogiCoy could not list ID.me MFA methods: ' . $failure);
        }

        $methods = [];
        foreach ((array)($response['idmeMFAList'] ?? []) as $item) {
            if (!is_array($item) || trim((string)($item['uuid'] ?? '')) === '') {
                continue;
            }
            $method = [
                'uuid' => trim((string)$item['uuid']),
                'name' => trim((string)($item['name'] ?? '')),
                'option' => trim((string)($item['option'] ?? '')),
                'label' => trim((string)($item['label'] ?? '')),
            ];
            $method['isPush'] = self::isPushMethod($method);
            $methods[] = $method;
        }

        return $methods;
    }

    /**
     * Start an MFA session with one of the prescriber's methods.
     *
     * The method is checked against the list the backend gives for this
     * prescriber first, so a uuid from anywhere else is refused before a
     * session is opened under it. Whatever session the prescriber had before
     * is replaced.
     *
     * @param array<string, mixed> $credential
     * @return array<string, mixed> the standing of the new session
     */
    public function start(array $credential, int $providerUserId, string $methodUuid): array
    {
        $methodUuid = trim($methodUuid);
        $method = null;
        foreach ($this->listMethods($credential) as $candidate) {
            if ($candidate['uuid'] === $methodUuid) {
                $method = $candidate;
                break;
            }
        }
        if ($method === null) {
            throw new BackendException('Choose one of the MFA methods on your ID.me account.');
        }

        $response = $this->backend->post(
            BackendClient::ENDPOINT_IDME_MFA_START,
            $this->identify($credential) + ['idmeMFAMethodTypeUuid' => $methodUuid],
            $credential
        );

        $failure = BackendClient::reportedFailure($response);
        if ($failure !== null) {
            throw new BackendException('LogiCoy could not start ID.me MFA: ' . $failure);
        }

        $outcome = self::parseOutcome($response, 'idmeStartMFAResponse');
        if ($outcome['session_uuid'] === '') {
            throw new BackendException('The LogiCoy backend did not return an MFA session.');
        }

        $this->store->recordStarted(
            (int)($credential['facility_id'] ?? 0),
            $providerUserId,
            $method,
            $outcome['session_uuid'],
            $outcome['status'],
            $outcome['expires_at'],
            $outcome['message']
        );

        return self::standing($this->store->find((int)($credential['facility_id'] ?? 0), $providerUserId));
    }

    /**
     * Submit the code ID.me delivered for the current session.
     *
     * A session that is already accepted and still valid is not re-submitted:
     * a double click, or a replayed request, answers from the record.
     *
     * @param array<string, mixed> $credential
     * @return array<string, mixed> the standing afterwards
     */
    public function complete(array $credential, int $providerUserId, string $code): array
    {
        $facilityId = (int)($credential['facility_id'] ?? 0);
        $row = $this->store->find($facilityId, $providerUserId);
        $standing = self::standing($row);
        if ($standing['active']) {
            return $standing;
        }
        $this->requireOpenSession($standing);

        $code = preg_replace('/\s+/', '', $code) ?? '';
        if ($code === '') {
            throw new BackendException('Enter the code ID.me sent you.');
        }

        $response = $this->backend->post(
            BackendClient::ENDPOINT_IDME_MFA_COMPLETE,
            $this->identify($credential) + [
                'idmeStartMFAResponseUuid' => $standing['session_uuid'],
                'idmeVerificationCode' => $code,
            ],
            $credential
        );

        return $this->recordAnswer(
            $facilityId,
            $providerUserId,
            $standing['session_uuid'],
            $response,
            'idmeCompleteMFAResponse'
        );
    }

    /**
     * Ask whether the push notification for the current session was accepted.
     *
     * Polled by the screen. An accepted, valid session answers from the
     * record without a backend call.
     *
     * @param array<string, mixed> $credential
     * @return array<string, mixed> the standing afterwards
     */
    public function checkDelivery(array $credential, int $providerUserId): array
    {
        $facilityId = (int)($credential['facility_id'] ?? 0);
        $row = $this->store->find($facilityId, $providerUserId);
        $standing = self::standing($row);
        if ($standing['active']) {
            return $standing;
        }
        $this->requireOpenSession($standing);

        $response = $this->backend->post(
            BackendClient::ENDPOINT_IDME_MFA_DELIVERY_STATUS,
            $this->identify($credential) + ['idmeStartMFAResponseUuid' => $standing['session_uuid']],
            $credential
        );

        return $this->recordAnswer(
            $facilityId,
            $providerUserId,
            $standing['session_uuid'],
            $response,
            'idmeDeliveryCheckMFAStatusResponse'
        );
    }

    /**
     * The current session as the screens and the send gate read it.
     *
     * ACCEPTED is trusted only until the expires time the backend gave, and
     * never without one: a session whose validity cannot be known does not
     * unlock a controlled substance.
     *
     * @param array<string, mixed>|null $row as stored
     * @return array{status: string, session_uuid: string, method_name: string, method_label: string,
     *     is_push: bool, expires_at: ?string, seconds_left: ?int, active: bool, message: string}
     */
    public static function standing(?array $row, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $status = strtoupper(trim((string)($row['status'] ?? '')));
        $expiresAt = self::parseStoredTime((string)($row['expires_at'] ?? ''));

        $result = [
            'status' => self::STATUS_NONE,
            'session_uuid' => trim((string)($row['session_uuid'] ?? '')),
            'method_name' => (string)($row['mfa_method_name'] ?? ''),
            'method_label' => (string)($row['mfa_method_label'] ?? ''),
            'is_push' => $row !== null && self::isPushMethod([
                'name' => (string)($row['mfa_method_name'] ?? ''),
                'option' => (string)($row['mfa_method_option'] ?? ''),
            ]),
            'expires_at' => $expiresAt?->format(DATE_ATOM),
            'seconds_left' => null,
            'active' => false,
            'message' => (string)($row['status_message'] ?? ''),
        ];

        if ($row === null || $status === '' || $result['session_uuid'] === '') {
            return $result;
        }

        if ($expiresAt === null) {
            // Both the open and the accepted state are time-limited; with no
            // time there is nothing to trust.
            $result['status'] = self::STATUS_EXPIRED;
            return $result;
        }

        $secondsLeft = $expiresAt->getTimestamp() - $now->getTimestamp();
        $result['seconds_left'] = max(0, $secondsLeft);

        if ($secondsLeft <= 0) {
            $result['status'] = self::STATUS_EXPIRED;
            return $result;
        }

        $result['status'] = in_array($status, [self::STATUS_WAITING, self::STATUS_ACCEPTED], true)
            ? $status
            : self::STATUS_FAILED;
        $result['active'] = $result['status'] === self::STATUS_ACCEPTED;

        return $result;
    }

    /**
     * Whether this prescriber may send a controlled substance right now.
     *
     * All three must hold: registered as EPCS, identity VERIFIED with ID.me
     * and unexpired, and an accepted MFA session that has not lapsed. Read
     * from the module's own records only; the backend applies the same checks
     * on send, and this exists so the refusal is explained before the call.
     *
     * @param array<string, mixed>|null $verificationRow logicoy_erx_idme_verification
     * @param array<string, mixed>|null $mfaRow logicoy_erx_idme_mfa_session
     * @return array{allowed: bool, reason: ?string, mfa: array<string, mixed>}
     */
    public static function controlledSendDecision(
        bool $isEpcs,
        ?array $verificationRow,
        ?array $mfaRow,
        ?DateTimeImmutable $now = null
    ): array {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $mfa = self::standing($mfaRow, $now);

        if (!$isEpcs) {
            return ['allowed' => false, 'reason' => self::REASON_NOT_EPCS, 'mfa' => $mfa];
        }

        $verification = IdmeVerificationService::standing($verificationRow, $now->setTime(0, 0));
        if ($verification['status'] !== IdmeVerificationService::STATUS_VERIFIED) {
            return ['allowed' => false, 'reason' => self::REASON_NOT_VERIFIED, 'mfa' => $mfa];
        }

        if ($mfa['active']) {
            return ['allowed' => true, 'reason' => null, 'mfa' => $mfa];
        }

        return [
            'allowed' => false,
            'reason' => $mfa['status'] === self::STATUS_EXPIRED ? self::REASON_MFA_EXPIRED : self::REASON_MFA_REQUIRED,
            'mfa' => $mfa,
        ];
    }

    /**
     * The session fields of a start, complete or delivery-status response,
     * normalised.
     *
     * The backend's own words are "waiting" and "accepted". Anything else is
     * kept as FAILED, with the word in the message for support: an unknown
     * status must never count as accepted.
     *
     * @param array<string, mixed> $response
     * @param string $key idmeStartMFAResponse, idmeCompleteMFAResponse or idmeDeliveryCheckMFAStatusResponse
     * @return array{session_uuid: string, status: string, expires_at: ?string, message: string}
     */
    public static function parseOutcome(array $response, string $key): array
    {
        $body = is_array($response[$key] ?? null) ? $response[$key] : [];
        $word = strtolower(trim((string)($body['status'] ?? '')));
        $errors = is_array($body['errors'] ?? null) ? $body['errors'] : [];

        $status = match ($word) {
            'accepted' => self::STATUS_ACCEPTED,
            'waiting', 'pending' => self::STATUS_WAITING,
            default => self::STATUS_FAILED,
        };

        $parts = [];
        $candidates = [$errors['base'] ?? '', $body['message'] ?? '', $errors['code'] ?? '', $body['code'] ?? ''];
        foreach ($candidates as $value) {
            $value = trim((string)$value);
            if ($value !== '' && !in_array($value, $parts, true)) {
                $parts[] = $value;
            }
        }
        if ($status === self::STATUS_FAILED && $parts === []) {
            $parts[] = $word === '' ? 'ID.me reported no status' : 'ID.me reported status "' . $word . '"';
        }

        return [
            'session_uuid' => trim((string)($body['uuid'] ?? '')),
            'status' => $status,
            'expires_at' => self::parseBackendTime((string)($body['expires'] ?? '')),
            'message' => substr(implode(' — ', $parts), 0, 500),
        ];
    }

    /**
     * Push is the only method without a code. The spec names the methods
     * but not their option codes, so both the name and the option are read.
     *
     * @param array<string, mixed> $method
     */
    public static function isPushMethod(array $method): bool
    {
        $option = strtolower((string)($method['option'] ?? ''));
        $name = strtolower((string)($method['name'] ?? ''));

        return str_contains($option, 'push') || str_contains($name, 'push');
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function recordAnswer(
        int $facilityId,
        int $providerUserId,
        string $sessionUuid,
        array $response,
        string $key
    ): array {
        $failure = BackendClient::reportedFailure($response);
        if ($failure !== null) {
            throw new BackendException('LogiCoy could not check ID.me MFA: ' . $failure);
        }

        $outcome = self::parseOutcome($response, $key);
        // The answer may echo the session uuid; a different one is a backend
        // fault and must not move this prescriber's record.
        if ($outcome['session_uuid'] !== '' && $outcome['session_uuid'] !== $sessionUuid) {
            throw new BackendException('The LogiCoy backend answered for a different MFA session.');
        }

        $this->store->recordOutcome(
            $facilityId,
            $providerUserId,
            $sessionUuid,
            $outcome['status'],
            $outcome['expires_at'],
            $outcome['message']
        );

        return self::standing($this->store->find($facilityId, $providerUserId));
    }

    /**
     * @param array<string, mixed> $standing
     */
    private function requireOpenSession(array $standing): void
    {
        if ($standing['status'] === self::STATUS_NONE) {
            throw new BackendException('Start MFA and choose a method first.');
        }
        if ($standing['status'] === self::STATUS_EXPIRED) {
            throw new BackendException('This MFA session has expired. Start MFA again.');
        }
    }

    /**
     * The three identifiers every MFA call carries.
     *
     * @param array<string, mixed> $credential
     * @return array<string, string>
     */
    private function identify(array $credential): array
    {
        $clinician = CredentialStore::clinicianPkUuid($credential);
        $practice = trim((string)($credential['erx_practice_uuid'] ?? ''));
        $location = trim((string)($credential['erx_location_uuid'] ?? ''));
        if ($clinician === '' || $practice === '' || $location === '') {
            throw new BackendException('Register the prescriber with LogiCoy before using ID.me MFA.');
        }

        return [
            'transactionUuid' => PrescribingService::newTransactionId(),
            'erxClinicianUserPKUuid' => $clinician,
            'erxPracticePKUuid' => $practice,
            'erxPracticeServiceLocPKUuid' => $location,
        ];
    }

    /**
     * The backend's time ("2026-03-16T15:08:37Z"), as a UTC DATETIME string
     * for storage, or null when unusable.
     */
    private static function parseBackendTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            // A bare time without a zone is read as UTC, which is what the
            // backend's examples carry.
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /** A stored UTC DATETIME string, back as a time. */
    private static function parseStoredTime(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
