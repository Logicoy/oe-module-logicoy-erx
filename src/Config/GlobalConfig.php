<?php

/**
 * Module globals, surfaced under Administration → Globals → LogiCoy eRx.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Config;

use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Services\Globals\GlobalSetting;

class GlobalConfig
{
    public const CONFIG_SECTION = 'LogiCoy eRx';

    public const BACKEND_BASE_URL = 'logicoy_erx_backend_base_url';
    public const BACKEND_TOKEN_URL = 'logicoy_erx_backend_token_url';
    public const ENABLE_MODULE = 'logicoy_erx_enable';
    public const WRITE_TO_PRESCRIPTIONS = 'logicoy_erx_write_to_prescriptions';
    public const REPLACE_RX_BUTTONS = 'logicoy_erx_replace_rx_buttons';
    public const POLL_ENABLED = 'logicoy_erx_poll_enabled';
    public const QUEUE_MAX_ATTEMPTS = 'logicoy_erx_queue_max_attempts';
    public const HTTP_TIMEOUT = 'logicoy_erx_http_timeout';
    public const DEBUG_LOGGING = 'logicoy_erx_debug_logging';
    public const WEBHOOK_ENABLED = 'logicoy_erx_webhook_enabled';

    /*
     * Which dispensing events the pharmacy is asked to report back (RxFill)
     * for every prescription sent from here. Set on the backend with
     * /rx-change-fill-indicator right after each send; the pharmacy's
     * answers arrive as RX_FILL notifications and land on the sent
     * prescription's fill status. One of the FILL_INDICATORS codes, or
     * FILL_INDICATOR_OFF to send nothing.
     */
    public const FILL_INDICATOR = 'logicoy_erx_fill_indicator';
    public const FILL_INDICATOR_OFF = '0';
    public const FILL_INDICATOR_DEFAULT = '1';

    /**
     * rxFillIndicatorTypeRef codes, as the eRx API defines them. The labels
     * are the API's own names for the codes; the screen shows them, and the
     * code is what is sent.
     */
    public const FILL_INDICATORS = [
        '1' => 'All fill statuses',
        '2' => 'All fill statuses except transferred',
        '3' => 'Dispensed and partially dispensed',
        '4' => 'Partially dispensed and not dispensed',
        '5' => 'Not dispensed and transferred',
        '6' => 'Partially dispensed',
        '7' => 'Not dispensed',
        '8' => 'Cancel all fill statuses',
    ];

    /*
     * The shared secret LogiCoy presents when it pushes to public/webhook.php.
     * Generated on the module screen, never typed, and stored encrypted with
     * CryptoGen — it is the only thing standing between that public URL and
     * the notification table.
     */
    public const WEBHOOK_SECRET = 'logicoy_erx_webhook_secret';

    /*
     * Only what LogiCoy actually issues to a practice lives here.
     *
     * The practice, service location and clinician UUIDs are deliberately NOT
     * globals: the backend mints them when those records are registered, so
     * they are provisioned from the credentials screen and stored in
     * logicoy_erx_credentials. Typing them by hand would mean transcribing a
     * value the API already returned.
     *
     * The signing key is not here either — globals.gl_value is varchar(255)
     * and an encrypted RSA key is ~2,400 characters, so MySQL rejects it.
     */
    public const ERX_CLIENT_ID = 'logicoy_erx_client_id';
    public const ERX_CLIENT_REGISTRATION_UUID = 'logicoy_erx_client_registration_uuid';

    /*
     * The *path* to the signing key, not the key itself.
     *
     * globals.gl_value is varchar(255) and a PKCS#8 RSA key is around 1,700
     * characters, so MySQL rejects the key outright (error 1406). The globals
     * screen also renders only css / encrypted / encrypted_hash / tabs_css and
     * has no file upload. Storing the path keeps key management here while
     * respecting both limits.
     */
    public const ERX_SIGNING_KEY_PATH = 'logicoy_erx_signing_key_path';

    private array $globals;

    public function __construct(array $globals)
    {
        $this->globals = $globals;
    }

    public function getBackendBaseUrl(): string
    {
        return rtrim((string)($this->globals[self::BACKEND_BASE_URL] ?? ''), '/');
    }

    public function getBackendTokenUrl(): string
    {
        $explicit = trim((string)($this->globals[self::BACKEND_TOKEN_URL] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $base = $this->getBackendBaseUrl();
        return $base === '' ? '' : $base . '/oauth2/token';
    }

    public function isModuleEnabled(): bool
    {
        return !empty($this->globals[self::ENABLE_MODULE]);
    }

    /**
     * Defaults to on when never configured.
     *
     * These are no longer seeded by the globals screen, so an absent row means
     * "not configured yet" and must not read as "switched off" — that would
     * silently stop reflecting prescriptions on a fresh install.
     */
    public function shouldWriteToPrescriptions(): bool
    {
        return $this->boolWithDefault(self::WRITE_TO_PRESCRIPTIONS, true);
    }

    public function isPollEnabled(): bool
    {
        return $this->boolWithDefault(self::POLL_ENABLED, true);
    }

    /**
     * Whether OpenEMR's own prescription buttons (the Eye Exam Impression/Plan
     * eRx button, the Prescriptions card's edit button) open the LogiCoy
     * screen instead of core's prescription list. On unless switched off.
     */
    public function shouldReplaceRxButtons(): bool
    {
        return $this->boolWithDefault(self::REPLACE_RX_BUTTONS, true);
    }

    private function boolWithDefault(string $key, bool $default): bool
    {
        if (!isset($this->globals[$key]) || $this->globals[$key] === '') {
            return $default;
        }

        return !empty($this->globals[$key]);
    }

    /**
     * Off until an administrator turns it on and generates a secret; a fresh
     * install exposes no unauthenticated endpoint.
     */
    public function isWebhookEnabled(): bool
    {
        return !empty($this->globals[self::WEBHOOK_ENABLED]);
    }

    public function hasWebhookSecret(): bool
    {
        return trim((string)($this->globals[self::WEBHOOK_SECRET] ?? '')) !== '';
    }

    /**
     * The decrypted shared secret, or '' when none is set or it no longer
     * decrypts (key material changed), so the endpoint fails closed.
     */
    public function getWebhookSecret(?CryptoGen $cryptoGen = null): string
    {
        $stored = trim((string)($this->globals[self::WEBHOOK_SECRET] ?? ''));
        if ($stored === '') {
            return '';
        }

        $plain = ($cryptoGen ?? new CryptoGen())->decryptStandard($stored);

        return $plain === false ? '' : (string)$plain;
    }

    public function getQueueMaxAttempts(): int
    {
        $value = (int)($this->globals[self::QUEUE_MAX_ATTEMPTS] ?? 5);
        return $value > 0 ? $value : 5;
    }

    public function getHttpTimeout(): int
    {
        $value = (int)($this->globals[self::HTTP_TIMEOUT] ?? 30);
        return $value > 0 ? $value : 30;
    }

    public function isDebugLoggingEnabled(): bool
    {
        return !empty($this->globals[self::DEBUG_LOGGING]);
    }

    /**
     * The fill indicator to set on every prescription sent from here, or ''
     * when none is to be set.
     *
     * Never configured reads as the default, all fill statuses: a fresh
     * install should hear back from the pharmacy without anyone finding the
     * setting. Only the API's own codes are ever sent; a value that is not
     * one of them (a typo in the globals table) reads as the default too,
     * never as a code the backend would refuse.
     */
    public function getFillIndicator(): string
    {
        $value = trim((string)($this->globals[self::FILL_INDICATOR] ?? ''));
        if ($value === self::FILL_INDICATOR_OFF) {
            return '';
        }

        return isset(self::FILL_INDICATORS[$value]) ? $value : self::FILL_INDICATOR_DEFAULT;
    }

    /**
     * The screen's label for a fill indicator code, or the code itself for
     * one the API has since added.
     */
    public static function fillIndicatorLabel(string $code): string
    {
        $label = self::FILL_INDICATORS[$code] ?? '';

        return $label === '' ? $code : xl($label);
    }

    /**
     * The client id is stored with OpenEMR's 'encrypted' global type, so the
     * raw global holds ciphertext and has to be decrypted on read.
     */
    public function getErxClientId(): string
    {
        $stored = (string)($this->globals[self::ERX_CLIENT_ID] ?? '');
        if ($stored === '') {
            return '';
        }

        $plain = (new CryptoGen())->decryptFromDatabase($stored);

        // A value saved before the setting became encrypted decrypts to false;
        // fall back to it verbatim rather than losing the configuration.
        return $plain === false ? $stored : (string)$plain;
    }

    /**
     * The registration uuid LogiCoy issues alongside the client id. Required to
     * register a practice; not a secret, so it is stored as plain text.
     */
    public function getErxClientRegistrationUuid(): string
    {
        return trim((string)($this->globals[self::ERX_CLIENT_REGISTRATION_UUID] ?? ''));
    }

    /**
     * Absolute path to the signing key file, if configured under Globals.
     */
    public function getErxSigningKeyPath(): string
    {
        return trim((string)($this->globals[self::ERX_SIGNING_KEY_PATH] ?? ''));
    }

    /**
     * Practice level values that fill in a credential row's blanks.
     *
     * Only the issued credentials appear here — provisioned UUIDs come from the
     * credential row itself.
     *
     * @return array<string, string>
     */
    public function getErxIdentifiers(): array
    {
        return [
            'client_id' => $this->getErxClientId(),
            'erx_client_registration_uuid' => $this->getErxClientRegistrationUuid(),
            'signing_key_path' => $this->getErxSigningKeyPath(),
        ];
    }

    /**
     * What the Administration, Globals screen shows.
     *
     * Only the master switch. Everything else is edited on the module's own
     * screen, so that setup happens in one place rather than being split
     * between two screens that cannot both hold the same fields: the globals
     * form has no file upload and globals.gl_value is varchar(255), so the
     * signing key can never live there, and splitting the rest around it only
     * made the setup harder to follow.
     *
     * Storage is unchanged — OpenEMR loads $GLOBALS from every row of the
     * globals table, not just the registered ones, so these keep working.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function getSettings(): array
    {
        return [
            self::ENABLE_MODULE => [
                xl('Enable LogiCoy eRx'),
                'bool',
                '0',
                xl('Show the LogiCoy prescribing entry point in patient context. '
                    . 'Everything else is configured under Miscellaneous, LogiCoy eRx, Settings, or Modules, Manage Modules, LogiCoy eRx (cog icon).'),
            ],
        ];
    }

    /**
     * The connection and operational settings, edited on the module screen.
     *
     * Same storage as any other global; only the editing surface differs.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function getModuleScreenSettings(): array
    {
        return [
            self::BACKEND_BASE_URL => [
                xl('Backend base URL'),
                'text',
                '',
                xl('Base URL of the LogiCoy eRx API, for example https://eprescribing.logicoy.com/erxapi'),
            ],
            self::BACKEND_TOKEN_URL => [
                xl('Backend token URL'),
                'text',
                '',
                xl('OAuth2 token endpoint. Defaults to the base URL plus /oauth2/token.'),
            ],
            self::WRITE_TO_PRESCRIPTIONS => [
                xl('Write to the OpenEMR prescriptions table'),
                'bool',
                '1',
                xl('Reflect prescriptions written through LogiCoy into the native prescriptions table.'),
            ],
            self::REPLACE_RX_BUTTONS => [
                xl('Open LogiCoy eRx from OpenEMR\'s prescription buttons'),
                'bool',
                '1',
                xl('The eRx button in the Eye Exam Impression/Plan and the edit button on the Prescriptions card '
                    . 'open the LogiCoy prescribing screen instead of the native prescription list. '
                    . 'The native list stays reachable from that screen.'),
            ],
            self::FILL_INDICATOR => [
                xl('Ask the pharmacy to report fill status'),
                'select',
                self::FILL_INDICATOR_DEFAULT,
                xl('After each prescription is sent, tell the pharmacy which dispensing events to report back. '
                    . 'The answers appear as the pharmacy status on the sent prescription. '
                    . 'Prescribers need the RX_FILL_INDICATOR_CHANGE service level, '
                    . 'which registration and Update with LogiCoy ask for.'),
            ],
            self::POLL_ENABLED => [
                xl('Enable inbound polling'),
                'bool',
                '1',
                xl('Poll the backend for refill requests, RxChange and NewRx status on cron.'),
            ],
            self::WEBHOOK_ENABLED => [
                xl('Accept pushed notifications (webhook)'),
                'bool',
                '0',
                xl('Let the LogiCoy backend push refill, change, cancel, fill and history notifications to this '
                    . 'site. Needs a webhook secret, generated below, and a URL the backend can reach.'),
            ],
            self::QUEUE_MAX_ATTEMPTS => [
                xl('Queue retry limit'),
                'num',
                '5',
                xl('How many times a queue row is retried before it is parked for support.'),
            ],
            self::HTTP_TIMEOUT => [
                xl('Backend HTTP timeout (seconds)'),
                'num',
                '30',
                xl('Timeout applied to every call to the LogiCoy backend.'),
            ],
            self::DEBUG_LOGGING => [
                xl('Verbose logging'),
                'bool',
                '0',
                xl('Log request and response metadata. Never logs credentials or prescription content.'),
            ],
        ];
    }

    /**
     * The choices for a 'select' setting on the module screen, value => label.
     *
     * @return array<string, string>
     */
    public static function getSettingOptions(string $key): array
    {
        if ($key === self::FILL_INDICATOR) {
            $options = [self::FILL_INDICATOR_OFF => xl('Do not ask (no fill status)')];
            foreach (self::FILL_INDICATORS as $code => $label) {
                $options[$code] = xl($label);
            }

            return $options;
        }

        return [];
    }

    /**
     * @return GlobalSetting[]
     */
    public static function buildGlobalSettings(): array
    {
        $settings = [];
        foreach (self::getSettings() as $key => [$title, $type, $default, $description]) {
            $settings[$key] = new GlobalSetting($title, $type, $default, $description);
        }
        return $settings;
    }
}
