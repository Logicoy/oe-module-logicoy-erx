<?php

/**
 * The prescribing screen.
 *
 * Renders patient context and the prescription form; everything dynamic goes
 * through api.php. No clinical logic here — drug data, pharmacy directory and
 * transmission all live behind the LogiCoy backend.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';

use LogiCoy\ERx\Bootstrap;
use LogiCoy\ERx\Config\CredentialStore;
use LogiCoy\ERx\Config\FacilityResolver;
use LogiCoy\ERx\Config\GlobalConfig;
use LogiCoy\ERx\Config\ProviderProfileStore;
use LogiCoy\ERx\Config\SessionContext;
use LogiCoy\ERx\Identity\IdmeMfaService;
use LogiCoy\ERx\Identity\IdmeMfaSessionStore;
use LogiCoy\ERx\Identity\IdmeVerificationService;
use LogiCoy\ERx\Identity\IdmeVerificationStore;
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

$pid = (int)($_GET['pid'] ?? 0) ?: SessionContext::getInt('pid');
if ($pid <= 0) {
    echo xlt('No patient in context.');
    exit;
}

$patient = QueryUtils::fetchRecords(
    'SELECT fname, mname, lname, DOB, sex, phone_cell, phone_home, city, state FROM patient_data WHERE pid = ? LIMIT 1',
    [$pid]
)[0] ?? [];

$userId = SessionContext::getInt('authUserID');
$authUser = SessionContext::getString('authUser');
$credentials = new CredentialStore();

// Which practices this prescriber is registered at. A prescriber at more than
// one needs to say which they are prescribing under; users.facility_id holds
// only one and OpenEMR asks for a facility at login only when
// login_into_facility is on.
$prescribingFacilities = $userId > 0 ? $credentials->listPrescribingFacilities($userId) : [];
$allowedFacilities = array_column($prescribingFacilities, 'facility_id');

// A pick is honoured only for a facility this user actually has a credential
// for, so the parameter cannot be used to prescribe under someone else's.
$picked = (int)($_GET['facility_id'] ?? 0);
if ($picked > 0 && in_array($picked, $allowedFacilities, true)) {
    SessionContext::set(FacilityResolver::SESSION_KEY, $picked);
}

$facilityId = (new FacilityResolver())->resolve(
    SessionContext::values(['authUserID', 'facilityId', FacilityResolver::SESSION_KEY]),
    $GLOBALS
);

$credential = $credentials->getCredential($facilityId, $userId ?: null);

$facilityName = (string)(QueryUtils::fetchSingleValue(
    'SELECT name FROM facility WHERE id = ? LIMIT 1',
    'name',
    [$facilityId]
) ?? '');

$patientName = trim(($patient['fname'] ?? '') . ' ' . ($patient['lname'] ?? ''));
$dob = (string)($patient['DOB'] ?? '');
$age = '';
if ($dob !== '' && ($born = date_create($dob)) !== false) {
    $age = (string)$born->diff(new DateTime('today'))->y;
}
$csrf = CsrfUtils::collectCsrfToken(SessionWrapperFactory::getInstance()->getActiveSession());

// Saving and sending need a credential and this user's prescriber
// registration; without them the form is shown but cannot be submitted, and
// the warning above it says why.
$canPrescribe = $credential !== null && CredentialStore::clinicianUuid($credential) !== '';
?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(); ?>
    <title><?php echo xlt('Prescribe'); ?></title>
    <link rel="stylesheet" href="<?php echo attr(Bootstrap::assetUrl('prescribe.css')); ?>">
    <link rel="stylesheet" href="<?php echo attr(Bootstrap::assetUrl('erx-modal.css')); ?>">
</head>
<body class="body_top">
<div class="container-fluid erx">

    <div class="erx-banner">
        <div>
            <div class="erx-banner-title"><?php echo text($patientName ?: xl('Unnamed patient')); ?></div>
            <div class="erx-banner-facts">
                <span><?php echo text($dob !== '' ? xl('DOB') . ' ' . $dob : xl('No date of birth on file')); ?></span>
                <?php if ($age !== '') : ?>
                    <span><?php echo text($age . ' ' . xl('yrs')); ?></span>
                <?php endif; ?>
                <?php if (!empty($patient['sex'])) : ?>
                    <span><?php echo text($patient['sex']); ?></span>
                <?php endif; ?>
                <span><?php echo xlt('Patient'); ?> #<?php echo text((string)$pid); ?></span>
                <?php if ($facilityName !== '') : ?>
                    <span><?php echo xlt('Prescribing at'); ?> <?php echo text($facilityName); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap: .5rem;">
            <?php if (count($prescribingFacilities) > 1) : ?>
                <form method="get" class="form-inline">
                    <input type="hidden" name="pid" value="<?php echo attr((string)$pid); ?>">
                    <label class="mr-1 small text-muted" for="erx-facility"><?php echo xlt('Prescribing at'); ?></label>
                    <select class="form-control form-control-sm" id="erx-facility"
                            name="facility_id" onchange="this.form.submit();">
                        <?php foreach ($prescribingFacilities as $f) : ?>
                            <option value="<?php echo attr((string)$f['facility_id']); ?>"
                                <?php echo $f['facility_id'] === $facilityId ? 'selected' : ''; ?>>
                                <?php echo text($f['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
            <span id="erx-status" class="badge badge-light border"
                  data-ok="<?php echo xla('Connected to LogiCoy'); ?>"
                  data-fail="<?php echo xla('Not connected'); ?>"><?php echo xlt('Connecting...'); ?></span>
            <?php
            // Core's own list (print, fax, history) stays one click away when
            // this screen has taken over OpenEMR's prescription buttons.
            $nativeListUrl = rtrim((string)($GLOBALS['webroot'] ?? ''), '/')
                . '/controller.php?prescription&list&id=' . urlencode((string)$pid);
            ?>
            <a class="btn btn-link btn-sm px-0" href="<?php echo attr($nativeListUrl); ?>"
               onclick="top.restoreSession()"><?php echo xlt('OpenEMR prescription list'); ?></a>
        </div>
    </div>

    <?php if ($credential === null) : ?>
        <div class="alert alert-warning">
            <strong><?php echo xlt('No LogiCoy credential is loaded for this facility.'); ?></strong>
            <div class="small">
                <?php
                // Naming the facility matters: credentials are per facility, and
                // the commonest cause of this warning is a credential loaded
                // against a different one rather than no credential at all.
                // Naming the account matters for the same reason — which facility
                // is resolved depends on who is signed in, and more than one
                // account can be open in different browser windows at once.
                echo xlt('Signed in as') . ' ' . text($authUser) . '. '
                    . xlt('Facility') . ': ' . text($facilityName)
                    . ' (' . xlt('id') . ' ' . text((string)$facilityId) . ').';
                ?>
                <?php echo xlt('This is your default facility, set on your own user record.'); ?>
                <?php echo xlt('An administrator loads a credential for it under Miscellaneous, LogiCoy eRx, Settings.'); ?>
            </div>
        </div>
    <?php elseif (CredentialStore::clinicianUuid($credential) === '') : ?>
        <div class="alert alert-warning">
            <strong>
                <?php
                // Naming the account matters: the commonest cause of this is
                // being signed in as an administrator rather than as the
                // prescriber who was registered.
                echo xlt('You are signed in as') . ' ' . text($authUser)
                    . ', ' . xlt('who is not registered as a prescriber with LogiCoy.');
                ?>
            </strong>
            <div class="small">
                <?php
                echo xlt('Facility') . ': ' . text($facilityName)
                    . ' (' . xlt('id') . ' ' . text((string)$facilityId) . ').';
                ?>
                <?php if ($prescribingFacilities !== []) : ?>
                    <?php echo xlt('You are registered at another facility — choose it above.'); ?>
                <?php else : ?>
                    <?php echo xlt('An administrator registers prescribers under Miscellaneous, LogiCoy eRx, Settings, step 3.'); ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php
    // An EPCS prescriber whose ID.me proofing is missing, lapsing or lapsed
    // is told here, where they would otherwise find out from a refused send.
    // Read from this module's record, never a backend call on page load.
    $idmeStanding = null;
    $idmeRow = null;
    $mfaRow = null;
    $isEpcs = $credential !== null && $userId > 0 && (new ProviderProfileStore())->isEpcs($userId);
    if ($isEpcs) {
        $idmeRow = QueryUtils::existsTable(IdmeVerificationStore::TABLE)
            ? (new IdmeVerificationStore())->find($facilityId, $userId)
            : null;
        $mfaRow = QueryUtils::existsTable(IdmeMfaSessionStore::TABLE)
            ? (new IdmeMfaSessionStore())->find($facilityId, $userId)
            : null;
        $idmeStanding = IdmeVerificationService::standing($idmeRow);
    }
    // Whether a controlled substance could be sent right now: EPCS, VERIFIED
    // and an accepted, unexpired MFA session. The script reads this to decide
    // what to say when a scheduled drug is chosen; api.php decides on send.
    $controlledSend = IdmeMfaService::controlledSendDecision($isEpcs, $idmeRow, $mfaRow);
    $controlledMessage = match ($controlledSend['reason']) {
        IdmeMfaService::REASON_NOT_EPCS
            => xl('Your eRx prescribing type is not EPCS, so controlled substances cannot be prescribed under it.'),
        IdmeMfaService::REASON_NOT_VERIFIED => xl('Your identity is not verified with ID.me for this facility.'),
        IdmeMfaService::REASON_MFA_EXPIRED
            => xl('Your ID.me two-factor session has expired. Complete it again before sending.'),
        IdmeMfaService::REASON_MFA_REQUIRED => xl('Complete ID.me two-factor authentication before sending it.'),
        default => '',
    };
    $idmeUrl = 'idme.php?facility_id=' . $facilityId;
    ?>
    <?php $idmeVerified = $idmeStanding === null
        || $idmeStanding['status'] === IdmeVerificationService::STATUS_VERIFIED; ?>
    <?php if (!$idmeVerified) : ?>
        <div class="alert alert-warning">
            <strong><?php echo xlt('Your identity is not verified with ID.me for this facility.'); ?></strong>
            <?php echo xlt('Controlled substance prescriptions will be refused until it is.'); ?>
            <a href="idme.php?facility_id=<?php echo attr((string)$facilityId); ?>">
                <?php echo xlt('Verify with ID.me'); ?></a>
        </div>
    <?php elseif ($idmeStanding !== null && $idmeStanding['expiring_soon']) : ?>
        <div class="alert alert-warning py-2">
            <?php echo xlt('Your ID.me verification expires on'); ?>
            <?php echo text((string)$idmeStanding['expires_on']); ?>.
            <a href="idme.php?facility_id=<?php echo attr((string)$facilityId); ?>">
                <?php echo xlt('Verify again'); ?></a>
        </div>
    <?php endif; ?>
    <?php if ($isEpcs && $idmeVerified) : ?>
        <?php if ($controlledSend['allowed']) : ?>
            <div class="alert alert-success py-2" id="erx-mfa-banner"
                 data-expires="<?php echo attr((string)$controlledSend['mfa']['expires_at']); ?>">
                <?php echo xlt('ID.me two-factor authentication passed.'); ?>
                <?php echo xlt('Controlled substances can be sent for another'); ?>
                <span class="erx-mfa-left"></span>.
                <a href="<?php echo attr($idmeUrl); ?>"><?php echo xlt('Manage'); ?></a>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div id="erx-alert" class="alert" hidden></div>
    <div id="erx-toasts" class="erx-toasts" aria-live="polite"></div>

    <div class="card mb-3 border-warning" id="requests-card" hidden>
        <div class="card-header py-2"><strong><?php echo xlt('Pharmacy messages'); ?></strong></div>
        <div class="card-body" id="requests"></div>
    </div>

    <div class="row">
        <div class="col-lg-7">
            <div class="card mb-3 erx-compose">
                <div class="card-header py-2 d-flex align-items-center">
                    <strong id="rx-form-title"
                            data-label-new="<?php echo xla('New prescription'); ?>"
                            data-label-edit="<?php echo xla('Editing draft'); ?>"><?php echo xlt('New prescription'); ?></strong>
                    <button type="button" class="btn btn-link btn-sm ml-auto p-0" id="btn-cancel-edit" hidden>
                        <?php echo xlt('Cancel editing'); ?>
                    </button>
                </div>
                <div class="card-body">
                    <form id="rx-form" autocomplete="off" novalidate>
                        <input type="hidden" id="ndcNumber" name="ndcNumber">
                        <input type="hidden" id="drugName" name="drugName">
                        <input type="hidden" id="isControlled" name="isControlled" value="false">
                        <input type="hidden" id="pharmacyOrgId" name="pharmacyOrgId">
                        <?php // Its name, kept so the send and cancel prompts can say where it goes. ?>
                        <input type="hidden" id="pharmacyName" name="pharmacyName">
                        <?php // Set while an existing draft is being edited; blank for a new one. ?>
                        <input type="hidden" id="medicationUuid" name="medicationUuid">

                        <?php // 1. What is being prescribed. ?>
                        <section class="erx-step" id="step-drug">
                            <h6 class="erx-step-title"><span class="erx-step-num">1</span><?php echo xlt('Medication'); ?></h6>
                            <label class="sr-only" for="drug-search"><?php echo xlt('Drug'); ?></label>
                            <input type="text" class="form-control" id="drug-search"
                                   placeholder="<?php echo xla('Search by drug name, or paste an NDC'); ?>">
                            <div id="drug-results" class="erx-results" hidden></div>
                            <div id="drug-chosen" class="erx-chosen" hidden></div>
                            <div class="invalid-feedback" data-for="drug"><?php echo xlt('Choose a drug from the search results.'); ?></div>
                        </section>

                        <?php // 2. How it is taken and how much. ?>
                        <section class="erx-step" id="step-directions">
                            <h6 class="erx-step-title"><span class="erx-step-num">2</span><?php echo xlt('Directions and quantity'); ?></h6>
                            <div class="form-group">
                                <label class="small mb-1" for="sig"><?php echo xlt('Directions (sig)'); ?> <span class="req">*</span></label>
                                <input type="text" class="form-control" id="sig" name="sig"
                                       placeholder="<?php echo xla('e.g. Take 1 capsule by mouth three times daily'); ?>">
                                <div class="invalid-feedback"><?php echo xlt('Enter the directions.'); ?></div>
                            </div>
                            <div class="form-row">
                                <div class="form-group col-6 col-md-3">
                                    <label class="small mb-1" for="quantity"><?php echo xlt('Quantity'); ?> <span class="req">*</span></label>
                                    <input type="text" class="form-control" id="quantity" name="quantity" inputmode="decimal">
                                    <div class="invalid-feedback"><?php echo xlt('Required.'); ?></div>
                                </div>
                                <div class="form-group col-6 col-md-3">
                                    <label class="small mb-1" for="daysSupply"><?php echo xlt('Days supply'); ?> <span class="req">*</span></label>
                                    <input type="number" min="1" class="form-control" id="daysSupply" name="daysSupply">
                                    <div class="invalid-feedback"><?php echo xlt('At least 1.'); ?></div>
                                </div>
                                <div class="form-group col-6 col-md-3">
                                    <label class="small mb-1" for="refills"><?php echo xlt('Refills'); ?></label>
                                    <input type="number" min="0" value="0" class="form-control" id="refills" name="refills">
                                    <div class="invalid-feedback"><?php echo xlt('Cannot be negative.'); ?></div>
                                </div>
                                <div class="form-group col-6 col-md-3">
                                    <label class="small mb-1" for="effectiveDate"><?php echo xlt('Start date'); ?></label>
                                    <input type="date" class="form-control" id="effectiveDate" name="effectiveDate"
                                           value="<?php echo attr(date('Y-m-d')); ?>">
                                </div>
                            </div>
                            <?php // Unchecked by default: dispense as written. ?>
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input"
                                       id="substitutionAllowed" name="substitutionAllowed" value="true">
                                <label class="custom-control-label small" for="substitutionAllowed">
                                    <?php echo xlt('Substitution allowed'); ?>
                                </label>
                                <div class="small text-muted">
                                    <?php echo xlt('Leave unchecked to dispense as written.'); ?>
                                    <?php echo xlt('The pharmacy may not substitute a generic.'); ?>
                                </div>
                            </div>
                        </section>

                        <?php
                        // 3. Where it goes. Part of the form rather than a card
                        // of its own: a new draft cannot be saved without one,
                        // and finding that out from an error after pressing Save
                        // was the commonest stumble on this screen.
                        ?>
                        <section class="erx-step" id="step-pharmacy">
                            <h6 class="erx-step-title">
                                <span class="erx-step-num">3</span><?php echo xlt('Pharmacy'); ?>
                                <span class="req">*</span>
                            </h6>
                            <div id="ph-chosen" class="erx-chosen" hidden></div>
                            <div id="ph-keep" class="erx-keep small" hidden>
                                <?php echo xlt('This draft keeps the pharmacy it was saved with. Search below only to change it.'); ?>
                            </div>
                            <div id="ph-search">
                                <div class="form-row">
                                    <div class="col-sm-7 mb-2">
                                        <label class="sr-only" for="ph-name"><?php echo xlt('Pharmacy name'); ?></label>
                                        <input type="text" class="form-control" id="ph-name"
                                               placeholder="<?php echo xla('Pharmacy name'); ?>">
                                    </div>
                                    <div class="col-7 col-sm-3 mb-2">
                                        <label class="sr-only" for="ph-zip"><?php echo xlt('ZIP'); ?></label>
                                        <input type="text" class="form-control" id="ph-zip" inputmode="numeric"
                                               placeholder="<?php echo xla('ZIP'); ?>">
                                    </div>
                                    <div class="col-5 col-sm-2 mb-2">
                                        <button type="button" class="btn btn-outline-primary btn-block" id="btn-ph-search">
                                            <?php echo xlt('Search'); ?>
                                        </button>
                                    </div>
                                </div>
                                <div id="ph-results" class="erx-results" hidden></div>
                            </div>
                            <div class="invalid-feedback" data-for="pharmacy">
                                <?php echo xlt('Choose a pharmacy. A draft cannot be saved without one.'); ?>
                            </div>
                        </section>

                        <?php // 4. Optional extras. ?>
                        <section class="erx-step" id="step-notes">
                            <h6 class="erx-step-title">
                                <span class="erx-step-num">4</span><?php echo xlt('Notes to pharmacist'); ?>
                                <span class="text-muted font-weight-normal small"><?php echo xlt('optional'); ?></span>
                            </h6>
                            <label class="sr-only" for="notesToPharmacist"><?php echo xlt('Notes to pharmacist'); ?></label>
                            <input type="text" class="form-control" id="notesToPharmacist" name="notesToPharmacist">
                        </section>

                        <div class="erx-form-footer">
                            <button type="submit" class="btn btn-primary" id="btn-save"
                                    data-label-new="<?php echo xla('Save draft'); ?>"
                                    data-label-edit="<?php echo xla('Update draft'); ?>"
                                <?php echo $canPrescribe ? '' : 'disabled'; ?>>
                                <?php echo xlt('Save draft'); ?>
                            </button>
                            <span class="small text-muted" id="rx-missing"
                                  data-prefix="<?php echo xla('Still needed'); ?>"
                                  data-ready="<?php echo xla('Ready to save. It is sent from the drafts list.'); ?>"
                                  data-blocked="<?php echo xla('Saving is unavailable until the warning above is resolved.'); ?>"></span>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <strong><?php echo xlt('Drafts'); ?> <span class="text-muted font-weight-normal small"><?php echo xlt('ready to send'); ?></span></strong>
                    <button class="btn btn-link btn-sm p-0" id="btn-refresh"><?php echo xlt('Refresh'); ?></button>
                </div>
                <div class="card-body" id="drafts">
                    <p class="text-muted mb-0"><?php echo xlt('Loading...'); ?></p>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <strong><?php echo xlt('Sent prescriptions'); ?></strong>
                    <button class="btn btn-link btn-sm p-0" id="btn-refresh-sent"><?php echo xlt('Refresh'); ?></button>
                </div>
                <div class="card-body" id="sent">
                    <p class="text-muted mb-0"><?php echo xlt('Loading...'); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Asked for at Send time only. When a controlled draft is sent without an
// accepted MFA session, api.php refuses it with the reason, the screen opens
// this, the prescriber picks a method and enters the code (or accepts the
// push), and the send is retried the moment the session is active. The card
// inside is the same one the ID.me screen uses (assets/idme-mfa.js).
?>
<div id="erx-mfa-modal" class="erx-modal" hidden role="dialog" aria-modal="true"
     aria-labelledby="erx-mfa-modal-title">
    <div class="erx-modal-dialog">
        <div class="erx-modal-header">
            <strong id="erx-mfa-modal-title"><?php echo xlt('ID.me two-factor authentication'); ?></strong>
            <span class="badge badge-secondary erx-mfa-badge"></span>
        </div>
        <p class="small text-muted mb-2">
            <?php echo xlt('This is a controlled substance.'); ?>
            <?php echo xlt('ID.me confirms it is you before LogiCoy sends it:'); ?>
            <?php echo xlt('choose a method, then enter the code it sends or accept the push notification.'); ?>
            <?php echo xlt('The prescription is sent as soon as ID.me accepts.'); ?>
        </p>
        <div class="erx-mfa erx-mfa-in-modal" data-facility="<?php echo attr((string)$facilityId); ?>" data-manual="1">
            <div class="erx-mfa-status small mb-2"></div>
            <div class="erx-mfa-body"></div>
        </div>
        <div class="erx-modal-footer">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="erx-mfa-cancel">
                <?php echo xlt('Cancel'); ?>
            </button>
        </div>
    </div>
</div>

<script>
    window.erxConfig = {
        apiUrl: 'api.php',
        csrfToken: <?php echo js_escape($csrf); ?>,
        pid: <?php echo js_escape((string)$pid); ?>,
        facilityId: <?php echo js_escape((string)$facilityId); ?>,
        hasCredential: <?php echo $credential === null ? 'false' : 'true'; ?>,
        canPrescribe: <?php echo $canPrescribe ? 'true' : 'false'; ?>,
        // What happens when a scheduled drug is chosen. A draft may be saved
        // once the prescriber is EPCS and VERIFIED; sending it needs MFA too.
        controlled: {
            allowed: <?php echo $controlledSend['allowed'] ? 'true' : 'false'; ?>,
            reason: <?php echo js_escape((string)$controlledSend['reason']); ?>,
            mayDraft: <?php echo in_array(
                $controlledSend['reason'],
                [IdmeMfaService::REASON_NOT_EPCS, IdmeMfaService::REASON_NOT_VERIFIED],
                true
            ) ? 'false' : 'true'; ?>,
            mfaExpiresAt: <?php echo js_escape((string)$controlledSend['mfa']['expires_at']); ?>,
            message: <?php echo js_escape($controlledMessage); ?>,
            idmeUrl: <?php echo js_escape($idmeUrl); ?>
        }
    };
</script>
<script src="<?php echo attr(Bootstrap::assetUrl('erx-api.js')); ?>"></script>
<script src="<?php echo attr(Bootstrap::assetUrl('erx-confirm.js')); ?>"></script>
<script src="<?php echo attr(Bootstrap::assetUrl('requests.js')); ?>"></script>
<script src="<?php echo attr(Bootstrap::assetUrl('idme-mfa.js')); ?>"></script>
<script src="<?php echo attr(Bootstrap::assetUrl('prescribe.js')); ?>"></script>
</body>
</html>
