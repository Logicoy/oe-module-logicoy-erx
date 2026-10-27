<?php

/**
 * Accepts notifications the LogiCoy backend pushes to public/webhook.php.
 *
 * The backend's webhook model (/v1/webhook-model/rx/*) posts the same envelope
 * /v1/notifications/get returns — a pharmacyInitiatedTxNotificationList — for
 * renewal requests, change requests, cancel responses, fill status and history
 * responses. Each notification goes through NotificationPoller::process(), so
 * a push and a poll of the same notification, or a redelivered push, record it
 * once and run its follow-up once.
 *
 * Thin: authenticates, routes to a facility, delegates. No clinical logic.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Webhook;

use LogiCoy\ERx\Config\CredentialStore;
use LogiCoy\ERx\Config\GlobalConfig;
use LogiCoy\ERx\Queue\NotificationPoller;
use OpenEMR\Common\Logging\SystemLogger;
use Throwable;

class WebhookReceiver
{
    /** Larger than any notification batch the backend documents. */
    public const MAX_BODY_BYTES = 2_000_000;

    /** Header LogiCoy can use when it cannot set Authorization. */
    public const SECRET_HEADER = 'X-LogiCoy-Webhook-Secret';

    /**
     * The backend's webhook-model paths, accepted as ?event= so one URL can be
     * registered per event. Only used when a notification carries no
     * rxTypeName of its own — the item's own type always wins.
     */
    public const EVENT_TYPES = [
        'renewal-request' => 'RX_RENEWAL_REQUEST',
        'change-request' => 'RX_CHANGE_REQUEST',
        'cancel-response' => 'RX_CANCEL_RESPONSE',
        'fill-status' => 'RX_FILL_STATUS',
        'history-response' => 'RX_HISTORY_RESPONSE',
        'new-rx-notify-pending-verification' => 'RX_NEW',
        'pharmacy-initiated-new-rx-request' => NotificationPoller::TYPE_NEW_RX_REQUEST,
    ];

    private GlobalConfig $config;
    private string $secret;
    private CredentialStore $credentials;
    private NotificationPoller $poller;
    private SystemLogger $logger;

    public function __construct(
        GlobalConfig $config,
        string $secret,
        CredentialStore $credentials,
        NotificationPoller $poller,
        ?SystemLogger $logger = null
    ) {
        $this->config = $config;
        $this->secret = $secret;
        $this->credentials = $credentials;
        $this->poller = $poller;
        $this->logger = $logger ?? new SystemLogger();
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(string $presentedSecret, string $body, string $event = ''): array
    {
        // Indistinguishable from a missing page until an administrator opts in.
        if (!$this->config->isModuleEnabled() || !$this->config->isWebhookEnabled()) {
            return self::reply(404, '', 'Not found');
        }

        // Fail closed: enabled without a usable secret accepts nothing.
        if ($this->secret === '') {
            $this->logger->error('LogiCoy webhook is enabled but has no secret; generate one on the module screen');
            return self::reply(503, '', 'Webhook is not configured', true);
        }

        if ($presentedSecret === '' || !hash_equals($this->secret, $presentedSecret)) {
            $this->logger->warning('LogiCoy webhook rejected: missing or wrong secret');
            return self::reply(401, '', 'Unauthorized');
        }

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return self::reply(413, '', 'Payload too large');
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return self::reply(400, '', 'Body is not a JSON object');
        }

        $transactionUuid = trim((string)($payload['transactionUuid'] ?? ''));
        $registration = trim((string)($payload['clientRegistrationPkUuid'] ?? ''));
        $list = $payload['pharmacyInitiatedTxNotificationList'] ?? null;

        if ($transactionUuid === '' || $registration === '' || !is_array($list)) {
            return self::reply(
                400,
                $transactionUuid,
                'transactionUuid, clientRegistrationPkUuid and pharmacyInitiatedTxNotificationList are required'
            );
        }

        $candidates = $this->credentials->getPracticeCredentialsForRegistration($registration);
        if ($candidates === []) {
            // Identifiers only. A push for a registration this site does not
            // hold is misrouted, and retrying it will not help.
            $this->logger->warning('LogiCoy webhook for an unknown client registration', [
                'transaction_uuid' => $transactionUuid,
                'client_registration_uuid' => $registration,
            ]);
            return self::reply(400, $transactionUuid, 'Unknown clientRegistrationPkUuid');
        }

        $envelopePractice = trim((string)($payload['erxPracticePKUuid'] ?? ''));
        $fallbackType = self::EVENT_TYPES[strtolower(trim($event))] ?? '';
        $counts = ['received' => 0, 'processed' => 0, 'replayed' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($list as $notification) {
            $counts['received']++;

            if (!is_array($notification)) {
                $counts['skipped']++;
                continue;
            }

            if ($fallbackType !== '' && trim((string)($notification['rxTypeName'] ?? '')) === '') {
                $notification['rxTypeName'] = $fallbackType;
            }

            $credential = self::credentialFor($notification, $envelopePractice, $candidates);
            if ($credential === null) {
                $counts['skipped']++;
                continue;
            }

            try {
                $outcome = $this->poller->process($notification, $credential, (int)$credential['facility_id']);
            } catch (Throwable $e) {
                // Recording itself failed (the database, not the backend). The
                // message is ours, never the notification body.
                $this->logger->error('LogiCoy webhook could not record a notification', [
                    'transaction_uuid' => $transactionUuid,
                    'erx_notification_uuid' => (string)($notification['erxNotificationPkUuid'] ?? ''),
                    'error' => $e->getMessage(),
                ]);
                $outcome = 'failed';
            }

            $counts[$outcome]++;
        }

        $this->logger->info('LogiCoy webhook received', ['transaction_uuid' => $transactionUuid] + $counts);

        if ($counts['failed'] > 0) {
            // Ask for redelivery. What already succeeded replays as a no-op,
            // and a notification that keeps failing is given up on after the
            // queue retry limit, after which it too replays as a no-op.
            return self::reply(500, $transactionUuid, 'Some notifications could not be processed', true, $counts);
        }

        return self::reply(200, $transactionUuid, 'Webhook processed successfully', false, $counts);
    }

    /**
     * The event named by the path the backend posted to. It appends its own
     * webhook-model path to the registered URL (webhook.php/rx/renewal-request),
     * so the last segment is the event; an empty or bare path names none.
     */
    public static function eventFromPath(string $pathInfo): string
    {
        $segments = array_values(array_filter(explode('/', trim($pathInfo)), static fn($s) => $s !== ''));
        $last = strtolower(trim((string)end($segments)));

        return $last !== '' && preg_match('/^[a-z0-9-]+$/', $last) === 1 ? $last : '';
    }

    /**
     * The secret from Authorization: Bearer, or the dedicated header.
     *
     * @param array<string, mixed> $server $_SERVER
     * @param array<string, string> $headers getallheaders(), when available
     */
    public static function presentedSecret(array $server, array $headers = []): string
    {
        $authorization = (string)($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $dedicated = (string)($server['HTTP_X_LOGICOY_WEBHOOK_SECRET'] ?? '');

        // Some Apache setups drop Authorization from $_SERVER but keep it in
        // the raw headers.
        foreach ($headers as $name => $value) {
            if ($authorization === '' && strcasecmp((string)$name, 'Authorization') === 0) {
                $authorization = (string)$value;
            }
            if ($dedicated === '' && strcasecmp((string)$name, self::SECRET_HEADER) === 0) {
                $dedicated = (string)$value;
            }
        }

        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $authorization, $m) === 1) {
            return $m[1];
        }

        return trim($dedicated);
    }

    /**
     * Pick the facility a notification belongs to.
     *
     * By practice uuid when the notification or envelope names one. With a
     * single facility under the registration, that facility is the answer even
     * when the practice is not named; process() still drops a notification
     * whose named practice is not the facility's own.
     *
     * @param array<string, mixed> $notification
     * @param array<int, array<string, mixed>> $candidates
     * @return array<string, mixed>|null
     */
    private static function credentialFor(array $notification, string $envelopePractice, array $candidates): ?array
    {
        $practice = trim((string)($notification['practicePkUuid'] ?? '')) ?: $envelopePractice;

        if ($practice !== '') {
            foreach ($candidates as $candidate) {
                if (trim((string)($candidate['erx_practice_uuid'] ?? '')) === $practice) {
                    return $candidate;
                }
            }
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * Shaped like the backend's own WebhookModelRespDTO, plus counts.
     *
     * @param array<string, int> $summary
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function reply(
        int $status,
        string $transactionUuid,
        string $message,
        bool $serverError = false,
        array $summary = []
    ): array {
        $body = [
            'transactionUuid' => $transactionUuid,
            'transactionStatus' => [
                'statusCode' => (string)$status,
                'status' => $status === 200 ? 'OK' : 'ERROR',
                'statusMessage' => $message,
                'errorCode' => $status === 200 ? null : (string)$status,
                'serverError' => $serverError,
            ],
        ];

        if ($summary !== []) {
            $body['summary'] = $summary;
        }

        return ['status' => $status, 'body' => $body];
    }
}
