<?php

/**
 * Endpoint the LogiCoy backend pushes pharmacy notifications to.
 *
 *   POST {site}/interface/modules/custom_modules/oe-module-logicoy-erx/public/webhook.php
 *   Authorization: Bearer <webhook secret>
 *   Content-Type: application/json
 *
 * Optional query parameters: site=<OpenEMR site id> on a multisite install,
 * and event=<renewal-request|change-request|cancel-response|fill-status|
 * history-response|new-rx-notify-pending-verification|
 * pharmacy-initiated-new-rx-request> when one URL is registered per event.
 * The backend also names the event by appending its webhook-model path to
 * the URL (webhook.php/rx/renewal-request); that is read when event= is
 * absent.
 *
 * Off by default. Answers 404 until enabled on the module screen, and refuses
 * everything until a secret has been generated there.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// The caller is the LogiCoy backend, not a signed-in user. It authenticates
// with the shared secret checked by WebhookReceiver instead.
$ignoreAuth = true;

require_once __DIR__ . '/../../../../globals.php';

use LogiCoy\ERx\Client\BackendClient;
use LogiCoy\ERx\Config\CredentialStore;
use LogiCoy\ERx\Config\GlobalConfig;
use LogiCoy\ERx\Queue\NotificationPoller;
use LogiCoy\ERx\Webhook\WebhookReceiver;
use OpenEMR\Common\Logging\SystemLogger;

header('Content-Type: application/json');
header('Cache-Control: no-store');

$respond = static function (array $result): never {
    http_response_code($result['status']);
    echo json_encode($result['body'], JSON_THROW_ON_ERROR);
    exit;
};

$logger = new SystemLogger();
$config = new GlobalConfig($GLOBALS);

if (!$config->isModuleEnabled() || !$config->isWebhookEnabled()) {
    $respond(['status' => 404, 'body' => ['error' => 'Not found']]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $respond(['status' => 405, 'body' => ['error' => 'Method not allowed']]);
}

try {
    $credentials = new CredentialStore();
    $backend = new BackendClient($config, $credentials);
    $receiver = new WebhookReceiver(
        $config,
        $config->getWebhookSecret(),
        $credentials,
        new NotificationPoller($config, $backend, null, null, $logger, null, 'logicoy-erx-webhook'),
        $logger
    );

    // One byte over the limit is enough to know it is over.
    $body = (string)file_get_contents('php://input', false, null, 0, WebhookReceiver::MAX_BODY_BYTES + 1);

    $respond($receiver->handle(
        WebhookReceiver::presentedSecret($_SERVER, function_exists('getallheaders') ? (getallheaders() ?: []) : []),
        $body,
        (string)($_GET['event'] ?? '')
            ?: WebhookReceiver::eventFromPath((string)($_SERVER['PATH_INFO'] ?? $_SERVER['ORIG_PATH_INFO'] ?? ''))
    ));
} catch (Throwable $e) {
    // Never echo the exception: it can carry SQL or paths. Never log the body.
    $logger->error('LogiCoy webhook failed', ['error' => $e->getMessage()]);
    $respond(['status' => 500, 'body' => ['error' => 'Internal error']]);
}
