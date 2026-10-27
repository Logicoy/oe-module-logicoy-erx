<?php

/**
 * ID.me identity verification, for the signed-in prescriber.
 *
 * ID.me proofs the person, so this runs in the prescriber's own session:
 * "Verify with ID.me" asks LogiCoy for a link and sends the browser there;
 * ID.me sends it back here, and the page then asks LogiCoy for the result.
 * An administrator cannot verify on a prescriber's behalf.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';

use LogiCoy\ERx\Bootstrap;
use LogiCoy\ERx\Client\BackendClient;
use LogiCoy\ERx\Client\BackendException;
use LogiCoy\ERx\Config\CredentialStore;
use LogiCoy\ERx\Config\GlobalConfig;
use LogiCoy\ERx\Config\ProviderProfileStore;
use LogiCoy\ERx\Config\SessionContext;
use LogiCoy\ERx\Identity\IdmeMfaService;
use LogiCoy\ERx\Identity\IdmeMfaSessionStore;
use LogiCoy\ERx\Identity\IdmeReturn;
use LogiCoy\ERx\Identity\IdmeVerificationService;
use LogiCoy\ERx\Identity\IdmeVerificationStore;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;

if (!AclMain::aclCheckCore(Bootstrap::ACL_SECTION, Bootstrap::ACL_SUBSECTION)) {
    echo xlt('You are not authorized to prescribe.');
    exit;
}

$config = new GlobalConfig($GLOBALS);
if (!$config->isModuleEnabled()) {
    echo xlt('The LogiCoy eRx module is not enabled.');
    exit;
}

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$csrf = CsrfUtils::collectCsrfToken($session);
$userId = SessionContext::getInt('authUserID');
$authUser = SessionContext::getString('authUser');

$credentials = new CredentialStore();
$store = new IdmeVerificationStore();
$facilities = $userId > 0 ? $credentials->listPrescribingFacilities($userId) : [];
$allowed = array_column($facilities, 'facility_id');

$messages = [];
$errors = [];

// Where ID.me sends the prescriber afterwards: the module's landing page,
// which needs no session and links back to this screen with returned=1.
// site_addr_oath is the origin OpenEMR already publishes for OAuth2, so it is
// the one a browser can reach.
$returnUrlFor = static fn(int $facilityId): string => IdmeReturn::landingUrl(
    (string)($GLOBALS['site_addr_oath'] ?? ''),
    (string)($GLOBALS['webroot'] ?? ''),
    SessionContext::getString('site_id'),
    IdmeReturn::TARGET_SCREEN,
    $facilityId
);

$audit = static function (string $what, int $facilityId) use ($authUser): void {
    // The event only. The email is the prescriber's personal account.
    EventAuditLogger::getInstance()->newEvent(
        'logicoy-erx-idme',
        $authUser,
        SessionContext::getString('authProvider'),
        1,
        'ID.me ' . $what . ' for facility ' . $facilityId
    );
};

$facilityId = (int)($_POST['facility_id'] ?? $_GET['facility_id'] ?? 0);
if ($facilityId > 0 && !in_array($facilityId, $allowed, true)) {
    // A facility this user is not registered at reads as not chosen.
    $facilityId = 0;
}

$action = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token'] ?? '', $session)) {
        CsrfUtils::csrfViolation(false, true);
        echo xlt('This screen was open too long. Reload and try again.');
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
} elseif (!empty($_GET['returned']) && $facilityId > 0) {
    // Back from ID.me. The outcome is only knowable from LogiCoy, so ask.
    $action = 'check';
}

if ($action !== '' && $facilityId > 0) {
    $credential = $credentials->getCredential($facilityId, $userId);
    $existing = $store->find($facilityId, $userId);
    try {
        if ($credential === null) {
            throw new BackendException(xl('No LogiCoy credential is loaded for this facility.'));
        }
        $service = new IdmeVerificationService(new BackendClient($config, $credentials), $store);

        if ($action === 'initiate') {
            $email = (string)($_POST['idme_email'] ?? '');
            $link = $service->initiate($credential, $userId, $email, $returnUrlFor($facilityId));
            $audit('verification started', $facilityId);
            // Off to ID.me. The link came from LogiCoy and was checked to be
            // an https URL before it got here.
            header('Location: ' . $link);
            exit;
        }

        if ($action === 'check') {
            $email = (string)($existing['idme_email'] ?? '');
            $result = $service->checkStatus($credential, $userId, $email);
            $audit('status checked: ' . $result['status'], $facilityId);
            $messages[] = $result['status'] === IdmeVerificationService::STATUS_VERIFIED
                ? xl('Your identity is verified with ID.me.')
                : xl('ID.me reports') . ': ' . $result['status']
                    . ($result['message'] !== '' ? ' — ' . $result['message'] : '');
        }
    } catch (BackendException $e) {
        $errors[] = $e->getUserMessage();
    } catch (Throwable $e) {
        $errors[] = xl('ID.me verification failed') . ': ' . $e->getMessage();
    }
}

$profiles = new ProviderProfileStore();
$isEpcs = $userId > 0 && $profiles->isEpcs($userId);
$lastEmail = $userId > 0 ? $store->lastEmailFor($userId) : '';
// The MFA card needs its table; a site upgraded without the schema step is
// told so instead of failing on the first call.
$mfaTableReady = QueryUtils::existsTable(IdmeMfaSessionStore::TABLE);
$mfaStore = $mfaTableReady ? new IdmeMfaSessionStore() : null;
?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(); ?>
    <title><?php echo xlt('ID.me verification'); ?></title>
    <link rel="stylesheet" href="<?php echo attr(Bootstrap::assetUrl('prescribe.css')); ?>">
    <script>
        window.erxConfig = {
            apiUrl: 'api.php',
            csrfToken: <?php echo js_escape($csrf); ?>,
            pid: ''
        };
    </script>
    <script src="<?php echo attr(Bootstrap::assetUrl('erx-api.js')); ?>"></script>
    <script src="<?php echo attr(Bootstrap::assetUrl('idme-mfa.js')); ?>" defer></script>
</head>
<body class="body_top">
<div class="container-fluid mt-3 erx">
    <h2><?php echo xlt('ID.me identity verification'); ?></h2>
    <p class="text-muted">
        <?php echo xlt('Controlled substance prescribing (EPCS) requires LogiCoy to verify your identity'); ?>
        <?php echo xlt('through ID.me.'); ?>
        <?php echo xlt('Verification is done on the ID.me website in this browser and then confirmed with LogiCoy.'); ?>
        <?php echo xlt('Use the email address of your own ID.me account.'); ?>
        <?php echo xlt('It cannot be shared with another prescriber.'); ?>
    </p>

    <?php foreach ($messages as $m) : ?>
        <div class="alert alert-success py-2"><?php echo text($m); ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $e) : ?>
        <div class="alert alert-danger py-2"><?php echo text($e); ?></div>
    <?php endforeach; ?>

    <?php if (!$isEpcs) : ?>
        <div class="alert alert-info py-2">
            <?php echo xlt('Your eRx prescribing type is not EPCS, so ID.me verification is not required for you.'); ?>
            <?php echo xlt('An administrator can change it under Administration, Users.'); ?>
        </div>
    <?php endif; ?>

    <?php if ($facilities === []) : ?>
        <div class="alert alert-warning">
            <?php echo xlt('You are signed in as') . ' ' . text($authUser) . ', '
                . xlt('who is not registered as a prescriber with LogiCoy at any facility.'); ?>
            <?php echo xlt('An administrator registers prescribers under Miscellaneous, LogiCoy eRx, Settings, step 3.'); ?>
        </div>
    <?php endif; ?>

    <?php foreach ($facilities as $f) : ?>
        <?php
        $fid = (int)$f['facility_id'];
        $row = $store->find($fid, $userId);
        $standing = IdmeVerificationService::standing($row);
        $badge = [
            IdmeVerificationService::STATUS_VERIFIED => ['success', xl('Verified')],
            IdmeVerificationService::STATUS_EXPIRED => ['danger', xl('Expired')],
            IdmeVerificationService::STATUS_PENDING => ['warning', xl('Started, not confirmed')],
        ][$standing['status']] ?? ['secondary', xl('Not verified')];
        ?>
        <div class="card mb-3">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <strong><?php echo text($f['name']); ?></strong>
                <span class="badge badge-<?php echo attr($badge[0]); ?>"><?php echo text($badge[1]); ?></span>
            </div>
            <div class="card-body">
                <?php if ($standing['expires_on'] !== null) : ?>
                    <p class="small mb-2 <?php echo $standing['expiring_soon'] ? 'text-danger' : 'text-muted'; ?>">
                        <?php echo xlt('Verification expires on'); ?> <?php echo text($standing['expires_on']); ?>
                        <?php if ($standing['expiring_soon']) : ?>
                            — <?php echo xlt('verify again before then to keep prescribing controlled substances.'); ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if (!empty($row['checked_at'])) : ?>
                    <p class="small text-muted mb-2">
                        <?php echo xlt('Last checked'); ?> <?php echo text((string)$row['checked_at']); ?>
                    </p>
                <?php endif; ?>

                <form method="post" class="form-inline mb-2">
                    <input type="hidden" name="csrf_token" value="<?php echo attr($csrf); ?>">
                    <input type="hidden" name="facility_id" value="<?php echo attr((string)$fid); ?>">
                    <input type="hidden" name="action" value="initiate">
                    <label class="mr-2" for="idme_email_<?php echo attr((string)$fid); ?>">
                        <?php echo xlt('ID.me email'); ?></label>
                    <input type="email" class="form-control mr-2" required
                           id="idme_email_<?php echo attr((string)$fid); ?>" name="idme_email"
                           value="<?php echo attr((string)($row['idme_email'] ?? $lastEmail)); ?>">
                    <?php
                    // ID.me refuses to load inside a frame, and module pages
                    // open in OpenEMR's tab frame, so the redirect goes to a
                    // new tab. ID.me returns to this screen in that tab.
                    ?>
                    <button type="submit" class="btn btn-primary" formtarget="_blank">
                        <?php echo $standing['status'] === IdmeVerificationService::STATUS_VERIFIED
                            ? xlt('Verify again with ID.me')
                            : xlt('Verify with ID.me'); ?>
                    </button>
                </form>

                <?php if (!empty($row['idme_email'])) : ?>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo attr($csrf); ?>">
                        <input type="hidden" name="facility_id" value="<?php echo attr((string)$fid); ?>">
                        <input type="hidden" name="action" value="check">
                        <button type="submit" class="btn btn-outline-secondary btn-sm">
                            <?php echo xlt('Check status with LogiCoy'); ?>
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($isEpcs && $standing['status'] === IdmeVerificationService::STATUS_VERIFIED) : ?>
                    <?php
                    // The per-session second factor. Rendered from the stored
                    // session; everything live goes through api.php from the
                    // script, so a push can be polled and the clock counted down.
                    $mfa = IdmeMfaService::standing($mfaStore?->find($fid, $userId));
                    $mfaBadge = [
                        IdmeMfaService::STATUS_ACCEPTED => ['success', xl('Passed')],
                        IdmeMfaService::STATUS_WAITING => ['warning', xl('Waiting for you')],
                        IdmeMfaService::STATUS_FAILED => ['danger', xl('Not accepted')],
                        IdmeMfaService::STATUS_EXPIRED => ['secondary', xl('Expired')],
                    ][$mfa['status']] ?? ['secondary', xl('Not started')];
                    ?>
                    <hr>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <h6 class="mb-0"><?php echo xlt('Two-factor authentication for controlled substances'); ?></h6>
                        <span class="badge badge-<?php echo attr($mfaBadge[0]); ?> erx-mfa-badge">
                            <?php echo text($mfaBadge[1]); ?></span>
                    </div>
                    <p class="small text-muted mb-2">
                        <?php echo xlt('Before LogiCoy accepts a controlled substance prescription from you,'); ?>
                        <?php echo xlt('ID.me confirms it is you with a second factor.'); ?>
                        <?php echo xlt('It lasts for the time ID.me gives, then is done again.'); ?>
                        <?php echo xlt('The code or push notification comes from ID.me.'); ?>
                        <?php echo xlt('Nothing is stored here but the outcome.'); ?>
                    </p>
                    <?php if (!$mfaTableReady) : ?>
                        <div class="alert alert-warning py-2 mb-0">
                            <?php echo xlt('The MFA session table is missing.'); ?>
                            <?php echo xlt('Disable and re-enable the module to create it.'); ?>
                        </div>
                    <?php else : ?>
                        <div class="erx-mfa" data-facility="<?php echo attr((string)$fid); ?>"
                             data-status="<?php echo attr($mfa['status']); ?>"
                             data-push="<?php echo $mfa['is_push'] ? '1' : '0'; ?>"
                             data-expires="<?php echo attr((string)$mfa['expires_at']); ?>"
                             data-method="<?php echo attr($mfa['method_name']); ?>">
                            <div class="erx-mfa-status small mb-2"></div>
                            <div class="erx-mfa-body"></div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
</body>
</html>
