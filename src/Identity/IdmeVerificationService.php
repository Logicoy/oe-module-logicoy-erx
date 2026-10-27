<?php

/**
 * ID.me identity proofing for a registered prescriber.
 *
 * The proofing itself happens on ID.me, in the prescriber's own browser: the
 * backend hands back a link, the prescriber completes it there, ID.me sends
 * them back to this module, and check-status reports the result. Nothing
 * about the prescriber's identity passes through PHP beyond the email the
 * ID.me account is under.
 *
 * LogiCoy keys the clinician on practice and service location, so a
 * verification belongs to one prescriber registration, not one OpenEMR user.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Identity;

use LogiCoy\ERx\Client\BackendClient;
use LogiCoy\ERx\Client\BackendException;
use LogiCoy\ERx\Config\CredentialStore;
use LogiCoy\ERx\Prescription\PrescribingService;

class IdmeVerificationService
{
    public const STATUS_VERIFIED = 'VERIFIED';
    public const STATUS_NOT_VERIFIED = 'NOT_VERIFIED';
    public const STATUS_EXPIRED = 'EXPIRED';
    /** Link issued, outcome not yet checked. Ours, not the backend's. */
    public const STATUS_PENDING = 'PENDING';

    /** How far ahead of the expiry date the prescriber is warned. */
    public const WARN_DAYS_BEFORE_EXPIRY = 30;

    private BackendClient $backend;
    private IdmeVerificationStoreInterface $store;

    public function __construct(BackendClient $backend, IdmeVerificationStoreInterface $store)
    {
        $this->backend = $backend;
        $this->store = $store;
    }

    /**
     * Ask the backend for a verification link for this prescriber.
     *
     * @param array<string, mixed> $credential the prescriber's own credential row
     * @param string $returnUrl where ID.me sends the prescriber afterwards
     * @return string the link to open in a browser
     */
    public function initiate(array $credential, int $providerUserId, string $email, string $returnUrl): string
    {
        $email = self::normalizeEmail($email);
        if ($email === '') {
            throw new BackendException('Enter the email address of the prescriber\'s ID.me account.');
        }
        if ($this->store->emailHeldByAnother($email, $providerUserId)) {
            throw new BackendException(
                'That ID.me email is already used by another prescriber. '
                . 'LogiCoy allows one prescriber per ID.me account.'
            );
        }

        $response = $this->backend->post(
            BackendClient::ENDPOINT_IDME_INITIATE,
            $this->identify($credential) + [
                'idmePrimaryEmailId' => $email,
                'clientRedirectionUrl' => $returnUrl,
            ],
            $credential
        );

        $failure = BackendClient::reportedFailure($response);
        if ($failure !== null) {
            throw new BackendException('LogiCoy could not start ID.me verification: ' . $failure);
        }

        $link = trim((string)($response['idmeVerificationLink'] ?? ''));
        // Only an https link to ID.me is ever handed to a browser. Anything
        // else in that field is a backend fault, not a place to send a user.
        if (!self::isSafeLink($link)) {
            throw new BackendException('The LogiCoy backend did not return an ID.me verification link.');
        }

        $this->store->recordInitiated((int)($credential['facility_id'] ?? 0), $providerUserId, $email);

        return $link;
    }

    /**
     * Ask the backend how the prescriber stands, and remember the answer.
     *
     * @param array<string, mixed> $credential
     * @return array{status: string, expires_on: ?string, message: string}
     */
    public function checkStatus(array $credential, int $providerUserId, string $email): array
    {
        $email = self::normalizeEmail($email);
        if ($email === '') {
            throw new BackendException('Start verification first, so the ID.me email is on record.');
        }

        $response = $this->backend->post(
            BackendClient::ENDPOINT_IDME_STATUS,
            $this->identify($credential) + ['idmePrimaryEmailId' => $email],
            $credential
        );

        $failure = BackendClient::reportedFailure($response);
        if ($failure !== null) {
            throw new BackendException('LogiCoy could not check ID.me status: ' . $failure);
        }

        $result = self::parseStatus($response);
        $this->store->recordStatus(
            (int)($credential['facility_id'] ?? 0),
            $providerUserId,
            $email,
            $result['status'],
            $result['expires_on'],
            $result['message']
        );

        return $result;
    }

    /**
     * The status fields of a check-status response, normalised.
     *
     * A status outside the documented three is kept as NOT_VERIFIED rather
     * than trusted: an unknown word must never unlock controlled substances.
     *
     * @param array<string, mixed> $response
     * @return array{status: string, expires_on: ?string, message: string}
     */
    public static function parseStatus(array $response): array
    {
        $status = strtoupper(trim((string)($response['verificationStatus'] ?? '')));
        if (!in_array($status, [self::STATUS_VERIFIED, self::STATUS_NOT_VERIFIED, self::STATUS_EXPIRED], true)) {
            $status = self::STATUS_NOT_VERIFIED;
        }

        return [
            'status' => $status,
            'expires_on' => self::parseDate((string)($response['verificationExpDate'] ?? '')),
            'message' => substr(trim((string)($response['message'] ?? '')), 0, 500),
        ];
    }

    /**
     * How a stored verification stands today.
     *
     * The backend's VERIFIED is trusted only until the expiry date it gave;
     * past that the row reads as EXPIRED without a backend call, since the
     * backend itself says re-verification is needed on or after that date.
     *
     * @param array<string, mixed>|null $row as stored
     * @return array{status: string, expires_on: ?string, days_left: ?int, expiring_soon: bool}
     */
    public static function standing(?array $row, ?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $status = strtoupper((string)($row['verification_status'] ?? ''));
        $expiresOn = self::parseDate((string)($row['verification_exp_date'] ?? ''));

        if ($row === null || $status === '') {
            return [
                'status' => self::STATUS_NOT_VERIFIED,
                'expires_on' => null,
                'days_left' => null,
                'expiring_soon' => false,
            ];
        }

        $daysLeft = null;
        if ($expiresOn !== null) {
            $expiry = new \DateTimeImmutable($expiresOn);
            $daysLeft = (int)$today->diff($expiry)->format('%r%a');
            if ($status === self::STATUS_VERIFIED && $daysLeft < 0) {
                $status = self::STATUS_EXPIRED;
            }
        }

        return [
            'status' => $status,
            'expires_on' => $expiresOn,
            'days_left' => $daysLeft,
            'expiring_soon' => $status === self::STATUS_VERIFIED
                && $daysLeft !== null
                && $daysLeft <= self::WARN_DAYS_BEFORE_EXPIRY,
        ];
    }

    /**
     * The three identifiers every ID.me call carries.
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
            throw new BackendException('Register the prescriber with LogiCoy before verifying with ID.me.');
        }

        return [
            'transactionUuid' => PrescribingService::newTransactionId(),
            'erxClinicianUserPKUuid' => $clinician,
            'erxPracticePKUuid' => $practice,
            'erxPracticeServiceLocPKUuid' => $location,
        ];
    }

    public static function normalizeEmail(string $email): string
    {
        $email = trim($email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? '' : $email;
    }

    public static function isSafeLink(string $link): bool
    {
        $parts = parse_url($link);

        return is_array($parts)
            && strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && (string)($parts['host'] ?? '') !== '';
    }

    /** Y-m-d, or null when the backend sent nothing usable. */
    private static function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // Dates and date-times are both seen; take the date part.
        $time = strtotime(substr($value, 0, 10)) ?: strtotime($value);

        return $time === false ? null : date('Y-m-d', $time);
    }
}
