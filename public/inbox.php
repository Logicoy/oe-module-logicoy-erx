<?php

/**
 * Pharmacy request inbox.
 *
 * Every renewal, change and new prescription request awaiting a response at
 * the prescriber's facility, including requests for patients this install
 * never synced — those still need an answer, and a new prescription request
 * for them offers to add the patient to OpenEMR or link an existing chart
 * first. Below them, a read-only record of
 * pharmacy answers to cancel requests and of requests already answered. Everything dynamic goes through
 * api.php; nothing is sent from this page directly.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';

use LogiCoy\ERx\Bootstrap;
use LogiCoy\ERx\Config\FacilityResolver;
use LogiCoy\ERx\Config\GlobalConfig;
use LogiCoy\ERx\Config\SessionContext;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
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

$facilityId = (new FacilityResolver())->resolve(
    SessionContext::values(['authUserID', 'facilityId', FacilityResolver::SESSION_KEY]),
    $GLOBALS
);
$facilityName = (string)(QueryUtils::fetchSingleValue(
    'SELECT name FROM facility WHERE id = ? LIMIT 1',
    'name',
    [$facilityId]
) ?? '');

$csrf = CsrfUtils::collectCsrfToken(SessionWrapperFactory::getInstance()->getActiveSession());
?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(); ?>
    <title><?php echo xlt('LogiCoy eRx Inbox'); ?></title>
    <link rel="stylesheet" href="<?php echo attr(Bootstrap::assetUrl('prescribe.css')); ?>">
    <link rel="stylesheet" href="<?php echo attr(Bootstrap::assetUrl('erx-modal.css')); ?>">
</head>
<body class="body_top">
<div class="container-fluid mt-3 erx">
    <div class="d-flex justify-content-between align-items-center">
        <h2 class="mb-0"><?php echo xlt('Pharmacy messages'); ?></h2>
        <button class="btn btn-link btn-sm" id="btn-refresh"><?php echo xlt('Refresh'); ?></button>
    </div>
    <p class="text-muted">
        <?php echo xlt('Renewal, change and new prescription requests from pharmacies awaiting a response at'); ?>
        <?php echo text($facilityName ?: xl('this facility')); ?>.
        <?php echo xlt('New requests arrive when LogiCoy pushes them or the background worker polls.'); ?>
        <?php echo xlt('A request for a patient with no chart here can be added to OpenEMR'); ?>
        <?php echo xlt('or linked to an existing chart from its review.'); ?>
    </p>

    <div class="card">
        <div class="card-body" id="requests">
            <p class="text-muted mb-0"><?php echo xlt('Loading...'); ?></p>
        </div>
    </div>

    <h4 class="mt-4 mb-1"><?php echo xlt('Recent pharmacy updates'); ?></h4>
    <p class="text-muted">
        <?php echo xlt('Pharmacy answers to cancel requests, and requests already answered, from the last 30 days.'); ?>
        <?php echo xlt('Nothing here needs a response.'); ?>
    </p>

    <div class="card mb-3">
        <div class="card-body" id="updates">
            <p class="text-muted mb-0"><?php echo xlt('Loading...'); ?></p>
        </div>
    </div>
</div>

<script>
    window.erxConfig = {
        apiUrl: 'api.php',
        csrfToken: <?php echo js_escape($csrf); ?>,
        pid: ''
    };
</script>
<script src="<?php echo attr(Bootstrap::assetUrl('erx-api.js')); ?>"></script>
<script src="<?php echo attr(Bootstrap::assetUrl('erx-confirm.js')); ?>"></script>
<script src="<?php echo attr(Bootstrap::assetUrl('requests.js')); ?>"></script>
<script src="<?php echo attr(Bootstrap::assetUrl('updates.js')); ?>"></script>
<script>
    (function () {
        var patientUrl = function (pid) { return 'prescribe.php?pid=' + encodeURIComponent(pid); };
        var requests = new window.ErxRequests(document.getElementById('requests'), {
            scope: 'facility',
            showPatient: true,
            patientUrl: patientUrl
        });
        var updates = new window.ErxPharmacyUpdates(document.getElementById('updates'), {
            patientUrl: patientUrl
        });
        document.getElementById('btn-refresh').addEventListener('click', function (e) {
            e.preventDefault();
            requests.load();
            updates.load();
        });
        requests.load();
        updates.load();
    })();
</script>
</body>
</html>
