<?php

/**
 * Credential management screen.
 *
 * The signing key is read straight from the upload's temporary file, encrypted
 * with CryptoGen, and stored. It is never written to disk in the module
 * directory, never logged, and never rendered back to the browser.
 *
 * Running here rather than on the command line also removes the CLI's main
 * failure mode: encryption always happens as the web server user, so the key
 * material always matches what OpenEMR uses at runtime.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use LogiCoy\ERx\Bootstrap;
use LogiCoy\ERx\Client\BackendClient;
use LogiCoy\ERx\Client\BackendException;
use LogiCoy\ERx\Config\CredentialInput;
use LogiCoy\ERx\Config\CredentialStore;
use LogiCoy\ERx\Config\GlobalConfig;
use LogiCoy\ERx\Config\KeyStore;
use LogiCoy\ERx\Config\KeyStoreException;
use LogiCoy\ERx\Config\ProviderProfileInput;
use LogiCoy\ERx\Config\ProviderProfileStore;
use LogiCoy\ERx\Config\ProvisioningService;
use LogiCoy\ERx\Config\SessionContext;
use LogiCoy\ERx\Identity\IdmeReturn;
use LogiCoy\ERx\Identity\IdmeVerificationService;
use LogiCoy\ERx\Identity\IdmeVerificationStore;
use LogiCoy\ERx\Queue\BackgroundSync;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Core\Header;

// Credential management is an administrative act, not a prescribing one.
if (!AclMain::aclCheckCore('admin', 'super')) {
    echo xlt('You are not authorized to manage LogiCoy credentials.');
    exit;
}

// CsrfUtils needs the active session; globals.php has already started it.
$session = SessionWrapperFactory::getInstance()->getActiveSession();
$csrfToken = CsrfUtils::collectCsrfToken($session);

$store = new CredentialStore();
$messages = [];
$errors = [];
// Shown once, straight after it is generated, and never again.
$newWebhookSecret = '';

// Where ID.me sends the browser back to: the module's landing page, which
// needs no session and links on to this screen, opened directly, with the
// prescriber it was verifying (idme_returned=1). site_addr_oath is the origin
// OpenEMR already publishes for OAuth2, so it is the one a browser can reach.
// The Manage Modules iframe cannot be returned to; the screen renders on its
// own instead, with the result at the top.
$idmeReturnUrl = static fn(int $facilityId, int $providerUserId, bool $epcsUpgrade = false): string =>
    IdmeReturn::landingUrl(
        (string)($GLOBALS['site_addr_oath'] ?? ''),
        (string)($GLOBALS['webroot'] ?? ''),
        SessionContext::getString('site_id'),
        IdmeReturn::TARGET_CONFIG,
        $facilityId,
        $providerUserId,
        $epcsUpgrade
    );

// Back from ID.me. The outcome is only knowable from LogiCoy, so ask, and
// show the facility the prescriber belongs to.
$idmeReturnedFor = 0;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && !empty($_GET['idme_returned'])) {
    $facilityId = (int)($_GET['facility_id'] ?? 0);
    $idmeReturnedFor = (int)($_GET['provider_user_id'] ?? 0);
    $credential = $facilityId > 0 && $idmeReturnedFor > 0
        ? $store->getCredential($facilityId, $idmeReturnedFor)
        : null;
    try {
        if ($credential === null || !QueryUtils::existsTable(IdmeVerificationStore::TABLE)) {
            throw new BackendException(xl('Could not tell which prescriber came back from ID.me.'));
        }
        $idmeStore = new IdmeVerificationStore();
        $existing = $idmeStore->find($facilityId, $idmeReturnedFor);
        $result = (new IdmeVerificationService(new BackendClient(new GlobalConfig($GLOBALS), $store), $idmeStore))
            ->checkStatus($credential, $idmeReturnedFor, (string)($existing['idme_email'] ?? ''));
        EventAuditLogger::getInstance()->newEvent(
            'logicoy-erx-idme',
            SessionContext::getString('authUser'),
            SessionContext::getString('authProvider'),
            1,
            'ID.me status checked after return for prescriber ' . $idmeReturnedFor . ' at facility ' . $facilityId
            . ': ' . $result['status']
        );
        $messages[] = $result['status'] === IdmeVerificationService::STATUS_VERIFIED
            ? xl('Back from ID.me: the prescriber is verified.')
            : xl('Back from ID.me, LogiCoy reports') . ': ' . $result['status']
                . ($result['message'] !== '' ? ' — ' . $result['message'] : '')
                . ' ' . xl('Press Check status again in a moment if ID.me is still finishing.');

        if (!empty($_GET['epcs'])) {
            // The proofing was part of an ERX -> EPCS upgrade; say where that stands.
            $upgrade = (new ProvisioningService(new BackendClient(new GlobalConfig($GLOBALS), $store), $store))
                ->upgradeToEpcsStatus($credential);
            $messages[] = xl('EPCS upgrade') . ': ' . ($upgrade['request_status'] ?: '-')
                . ($upgrade['message'] !== '' ? ' — ' . $upgrade['message'] : '')
                . ' ' . xl('LogiCoy now holds the prescriber as') . ' '
                . ($upgrade['is_epcs'] ? 'EPCS' : ($upgrade['prescribing_type'] ?: 'NON_EPCS')) . '.';
        }
    } catch (BackendException $e) {
        $errors[] = $e->getUserMessage();
    } catch (Throwable $e) {
        $errors[] = xl('ID.me verification failed') . ': ' . $e->getMessage();
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token'] ?? '', $session)) {
        // Core's csrfNotVerified() prints a bare "Authentication Error" and
        // exits. This screen renders inside the Manage Modules iframe, where by
        // far the commonest cause is the page having sat open across a session
        // change — the token is then signed with a private key the session no
        // longer holds. Record the violation exactly as core does, then say
        // what to do about it rather than leaving the admin with two words.
        CsrfUtils::csrfViolation(false, true);
        echo '<div style="font-family:sans-serif;padding:1rem;">';
        echo '<p><strong>' . text(xl('This screen was open too long.')) . '</strong></p>';
        echo '<p>' . text(xl('Its security token no longer matches your session, so nothing was saved or sent.'))
            . '</p>';
        echo '<p>' . text(xl('Reload and try again.')) . ' '
            . text(xl('If you were registering, check whether it went through before registering twice.'))
            . '</p>';
        // Hardcoded rather than PHP_SELF: this file is always reached at the
        // same path, and PHP_SELF carries whatever the request line said.
        echo '<p><a href="moduleConfig.php">' . text(xl('Reload this screen')) . '</a></p>';
        echo '</div>';
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'deactivate') {
        $id = (int)($_POST['credential_id'] ?? 0);
        if ($id > 0) {
            $store->deactivate($id);
            EventAuditLogger::getInstance()->newEvent(
                'logicoy-erx-credential',
                SessionContext::getString('authUser'),
                SessionContext::getString('authProvider'),
                1,
                'LogiCoy credential ' . $id . ' deactivated'
            );
            $messages[] = xl('Credential deactivated.');
        }
    }

    // ID.me identity verification from this screen. The browser is sent to
    // ID.me and comes back to this same screen, which then asks LogiCoy for
    // the result. ID.me proofs the person at the keyboard, so the prescriber
    // signs in to ID.me with their own account; this only carries the link.
    if ($action === 'idme-initiate' || $action === 'idme-check') {
        $facilityId = (int)($_POST['facility_id'] ?? 0);
        $providerUserId = (int)($_POST['provider_user_id'] ?? 0);
        $config = new GlobalConfig($GLOBALS);
        $credential = $facilityId > 0 && $providerUserId > 0
            ? $store->getCredential($facilityId, $providerUserId)
            : null;
        try {
            if ($credential === null || CredentialStore::clinicianPkUuid($credential) === '') {
                throw new BackendException(xl('Register the prescriber with LogiCoy first.'));
            }
            if (!QueryUtils::existsTable(IdmeVerificationStore::TABLE)) {
                throw new BackendException(
                    xl('The ID.me table is missing. Disable and re-enable the module to create it.')
                );
            }
            $idmeStore = new IdmeVerificationStore();
            $idmeService = new IdmeVerificationService(new BackendClient($config, $store), $idmeStore);

            if ($action === 'idme-initiate') {
                $link = $idmeService->initiate(
                    $credential,
                    $providerUserId,
                    (string)($_POST['idme_email'] ?? ''),
                    $idmeReturnUrl($facilityId, $providerUserId)
                );
                EventAuditLogger::getInstance()->newEvent(
                    'logicoy-erx-idme',
                    SessionContext::getString('authUser'),
                    SessionContext::getString('authProvider'),
                    1,
                    'ID.me verification started for prescriber ' . $providerUserId . ' at facility ' . $facilityId
                );
                // Off to ID.me. The link came from LogiCoy and was checked to
                // be an https URL before it got here.
                header('Location: ' . $link);
                exit;
            }

            $existing = $idmeStore->find($facilityId, $providerUserId);
            $result = $idmeService->checkStatus($credential, $providerUserId, (string)($existing['idme_email'] ?? ''));
            EventAuditLogger::getInstance()->newEvent(
                'logicoy-erx-idme',
                SessionContext::getString('authUser'),
                SessionContext::getString('authProvider'),
                1,
                'ID.me status checked for prescriber ' . $providerUserId . ' at facility ' . $facilityId
                . ': ' . $result['status']
            );
            $messages[] = $result['status'] === IdmeVerificationService::STATUS_VERIFIED
                ? xl('ID.me reports the prescriber as verified.')
                : xl('ID.me reports') . ': ' . $result['status']
                    . ($result['message'] !== '' ? ' — ' . $result['message'] : '');
        } catch (BackendException $e) {
            $errors[] = $e->getUserMessage();
        } catch (Throwable $e) {
            $errors[] = xl('ID.me verification failed') . ': ' . $e->getMessage();
        }
    }

    if (str_starts_with($action, 'provision-')) {
        $facilityId = (int)($_POST['facility_id'] ?? 0);
        $config = new GlobalConfig($GLOBALS);
        $credential = $facilityId > 0 ? $store->getCredential($facilityId) : null;

        if ($credential === null) {
            // Name the facility that was actually looked up. These steps act on
            // whichever facility the selector holds, which is not necessarily
            // the one just saved, and an unqualified "save a credential first"
            // is unreadable when a credential is listed directly above.
            $facilityName = $facilityId > 0
                ? (string)QueryUtils::fetchSingleValue(
                    'SELECT name FROM facility WHERE id = ?',
                    'name',
                    [$facilityId]
                )
                : '';
            $errors[] = $facilityId <= 0
                ? xl('Choose a facility to register.')
                : xl('No active credential for this facility') . ': '
                    . ($facilityName !== '' ? $facilityName . ' ' : '')
                    . '(' . xl('facility') . ' ' . $facilityId . '). '
                    . xl('Save a credential for that facility below before registering it.');
        } else {
            try {
                $provisioner = new ProvisioningService(
                    new BackendClient($config, $store),
                    $store
                );

                if ($action === 'provision-practice') {
                    $uuid = $provisioner->registerPractice(
                        $facilityId,
                        $credential,
                        // Falls back to the global for installs configured
                        // before this moved onto the credential.
                        trim((string)($credential['erx_client_registration_uuid'] ?? ''))
                            ?: $config->getErxClientRegistrationUuid()
                    );
                    $messages[] = xl('Practice registered.') . ' ' . $uuid;
                } elseif ($action === 'provision-location') {
                    $uuid = $provisioner->registerLocation($facilityId, $credential);
                    $messages[] = xl('Service location registered.') . ' ' . $uuid;
                } elseif ($action === 'provision-upgrade-epcs' || $action === 'provision-epcs-status') {
                    $providerUserId = (int)($_POST['provider_user_id'] ?? 0);
                    $providerCredential = $providerUserId > 0
                        ? $store->getCredential($facilityId, $providerUserId)
                        : null;
                    if ($providerCredential === null) {
                        throw new BackendException(xl('Register the prescriber with LogiCoy before upgrading them.'));
                    }

                    if ($action === 'provision-epcs-status') {
                        $upgrade = $provisioner->upgradeToEpcsStatus($providerCredential);
                        $messages[] = xl('EPCS upgrade') . ': ' . ($upgrade['request_status'] ?: '-')
                            . ($upgrade['verification_status'] !== ''
                                ? ' (' . xl('ID.me') . ' ' . $upgrade['verification_status'] . ')' : '')
                            . ($upgrade['message'] !== '' ? ' — ' . $upgrade['message'] : '')
                            . ' ' . xl('LogiCoy holds the prescriber as') . ' '
                            . ($upgrade['is_epcs'] ? 'EPCS' : ($upgrade['prescribing_type'] ?: 'NON_EPCS')) . '.';
                    } else {
                        $email = (string)($_POST['idme_email'] ?? '');
                        $upgrade = $provisioner->upgradeToEpcs(
                            $providerUserId,
                            $providerCredential,
                            $email,
                            $idmeReturnUrl($facilityId, $providerUserId, true)
                        );
                        // The proofing ID.me is about to do is the same one the
                        // verification record tracks, so it is recorded there
                        // and the return trip's check-status lands on it.
                        if (QueryUtils::existsTable(IdmeVerificationStore::TABLE)) {
                            (new IdmeVerificationStore())->recordInitiated(
                                $facilityId,
                                $providerUserId,
                                IdmeVerificationService::normalizeEmail($email)
                            );
                        }
                        EventAuditLogger::getInstance()->newEvent(
                            'logicoy-erx-provision',
                            SessionContext::getString('authUser'),
                            SessionContext::getString('authProvider'),
                            1,
                            'EPCS upgrade requested for prescriber ' . $providerUserId . ' at facility ' . $facilityId
                        );
                        if ($upgrade['link'] !== '') {
                            // Off to ID.me, in the tab the button opened.
                            header('Location: ' . $upgrade['link']);
                            exit;
                        }
                        $messages[] = xl('EPCS upgrade requested.') . ' '
                            . ($upgrade['description'] ?: xl('LogiCoy returned no ID.me link; press EPCS status.'));
                    }
                } elseif ($action === 'provision-update-clinician') {
                    // The prescriber's own row carries the clinician identifier
                    // the update names.
                    $providerUserId = (int)($_POST['provider_user_id'] ?? 0);
                    $providerCredential = $providerUserId > 0
                        ? $store->getCredential($facilityId, $providerUserId)
                        : null;
                    if ($providerCredential === null) {
                        throw new BackendException(xl('Register the prescriber with LogiCoy before updating them.'));
                    }
                    $outcome = $provisioner->updateClinician($providerUserId, $providerCredential);
                    $messages[] = xl('Prescriber details sent to LogiCoy.') . ' '
                        . ($outcome['epcs']
                            ? xl('DEA details and the controlled substance service level were included.')
                            : xl('The prescriber is ERX here, so no controlled substance level was requested.'))
                        . ' ' . xl('LogiCoy keeps the prescribing type it registered; ask LogiCoy to change it.');
                } elseif ($action === 'provision-clinician') {
                    $providerUserId = (int)($_POST['provider_user_id'] ?? 0);
                    if ($providerUserId <= 0) {
                        throw new BackendException(xl('Choose a prescriber to register.'));
                    }

                    $ids = $provisioner->registerClinician($providerUserId, $credential);

                    // A prescriber's clinician uuids are their own, so they are
                    // stored on a provider scoped credential rather than the
                    // practice row.
                    $store->save([
                        'scope_type' => CredentialStore::SCOPE_PROVIDER,
                        'facility_id' => $facilityId,
                        'provider_user_id' => $providerUserId,
                        'client_id' => $credential['client_id'],
                        'signing_key' => '',
                        'erx_practice_uuid' => $credential['erx_practice_uuid'],
                        'erx_location_uuid' => $credential['erx_location_uuid'],
                        'erx_clinician_user_uuid' => $ids['erx_clinician_user_uuid'],
                        'erx_clinician_user_pk_uuid' => $ids['erx_clinician_user_pk_uuid'],
                        'active' => 1,
                    ]);

                    $messages[] = xl('Prescriber registered.');
                }

                EventAuditLogger::getInstance()->newEvent(
                    'logicoy-erx-provision',
                    SessionContext::getString('authUser'),
                    SessionContext::getString('authProvider'),
                    1,
                    'LogiCoy ' . $action . ' for facility ' . $facilityId
                );
            } catch (BackendException $e) {
                $errors[] = $e->getUserMessage();
            } catch (Throwable $e) {
                $errors[] = xl('Registration failed') . ': ' . $e->getMessage();
            }
        }
    }

    if ($action === 'save-settings') {
        // Same storage as the globals screen — only the editing surface moved.
        // The settings are split across several forms on this screen, so only
        // the keys a form actually posted are written; the rest keep their value.
        $postedSettings = is_array($_POST['setting'] ?? null) ? $_POST['setting'] : [];
        foreach (array_keys(GlobalConfig::getModuleScreenSettings()) as $key) {
            if (!array_key_exists($key, $postedSettings)) {
                continue;
            }
            $posted = $postedSettings[$key];
            QueryUtils::sqlStatementThrowException(
                'REPLACE INTO globals SET gl_name = ?, gl_index = 0, gl_value = ?',
                [$key, (string)$posted]
            );
            // Keep this request consistent with what was just written.
            $GLOBALS[$key] = (string)$posted;
        }

        EventAuditLogger::getInstance()->newEvent(
            'logicoy-erx-settings',
            SessionContext::getString('authUser'),
            SessionContext::getString('authProvider'),
            1,
            'LogiCoy eRx connection settings updated'
        );

        $messages[] = xl('Settings saved.');
    }

    if ($action === 'generate-webhook-secret') {
        // Generated, never typed: 256 random bits, stored encrypted. Replacing
        // it locks out the old one at once, so LogiCoy has to be given the new
        // value before pushes resume.
        $newWebhookSecret = bin2hex(random_bytes(32));
        $encrypted = (new CryptoGen())->encryptStandard($newWebhookSecret);
        QueryUtils::sqlStatementThrowException(
            'REPLACE INTO globals SET gl_name = ?, gl_index = 0, gl_value = ?',
            [GlobalConfig::WEBHOOK_SECRET, $encrypted]
        );
        $GLOBALS[GlobalConfig::WEBHOOK_SECRET] = $encrypted;

        // The event, never the secret.
        EventAuditLogger::getInstance()->newEvent(
            'logicoy-erx-settings',
            SessionContext::getString('authUser'),
            SessionContext::getString('authProvider'),
            1,
            'LogiCoy eRx webhook secret generated'
        );
    }

    if ($action === 'save') {
        $signingKey = '';
        $upload = $_FILES['signing_key'] ?? null;
        $pasted = trim((string)($_POST['signing_key_text'] ?? ''));

        if ($pasted !== '') {
            // Pasting is offered because a PEM cannot live in Globals — the
            // gl_value column is varchar(255) and a PKCS#8 RSA key is ~1,700
            // characters, so MySQL rejects it outright.
            $signingKey = $pasted;
        }

        if (!empty($upload['name'])) {
            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $errors[] = xl('The key file did not upload successfully.');
            } elseif (!is_uploaded_file($upload['tmp_name'])) {
                // Guards against a crafted POST pointing at an arbitrary path.
                $errors[] = xl('Invalid upload.');
            } elseif (($upload['size'] ?? 0) > 32768) {
                $errors[] = xl('That file is too large to be a private key.');
            } else {
                // Read from the temp file directly. It is never moved into the
                // module directory, where it could become web reachable.
                // A file wins over a paste if somebody supplies both.
                $signingKey = (string)file_get_contents($upload['tmp_name']);
            }
        }

        if ($errors === []) {
            $existing = $store->getCredential((int)($_POST['facility_id'] ?? 0)) ?? [];
            $input = new CredentialInput([
                'scope_type' => $_POST['scope_type'] ?? CredentialStore::SCOPE_PRACTICE,
                'facility_id' => $_POST['facility_id'] ?? 0,
                'provider_user_id' => $_POST['provider_user_id'] ?? null,
                'client_id' => $_POST['client_id'] ?? '',
                'signing_key' => $signingKey,
                'signing_key_path' => $_POST['signing_key_path'] ?? '',
                'jwt_kid' => $_POST['jwt_kid'] ?? null,
                'erx_client_registration_uuid' => $_POST['erx_client_registration_uuid'] ?? null,
                // Provisioned, not typed. Preserved across an edit of the
                // client id or key.
                'erx_practice_uuid' => $existing['erx_practice_uuid'] ?? null,
                'erx_location_uuid' => $existing['erx_location_uuid'] ?? null,
                'erx_clinician_user_uuid' => $existing['erx_clinician_user_uuid'] ?? null,
                'erx_clinician_user_pk_uuid' => $existing['erx_clinician_user_pk_uuid'] ?? null,
                'active' => 1,
            ]);

            if (!$input->isValid()) {
                $errors = array_merge($errors, $input->getErrors());
            } else {
                try {
                    $store->save($input->toArray());

                // Audited, but the key itself never appears in the trail.
                EventAuditLogger::getInstance()->newEvent(
                    'logicoy-erx-credential',
                    SessionContext::getString('authUser'),
                    SessionContext::getString('authProvider'),
                    1,
                    'LogiCoy credential saved for facility ' . (int)$input->toArray()['facility_id']
                    . ($signingKey === '' ? ' (key unchanged)' : ' (new signing key stored)')
                );

                    $messages[] = $signingKey === ''
                        ? xl('Credential saved. The stored private key was left unchanged.')
                        : xl('Credential saved. The private key was written to the certificates directory.');
                } catch (KeyStoreException $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }

        // Do not leave key material sitting in memory longer than needed.
        unset($signingKey);
    }
}

$credentials = $store->listCredentials();
$facilities = QueryUtils::fetchRecords('SELECT id, name FROM facility ORDER BY name', []);
$providers = QueryUtils::fetchRecords(
    "SELECT id, CONCAT(lname, ', ', fname) AS name, npi FROM users
     WHERE active = 1 AND username IS NOT NULL AND username != '' ORDER BY lname, fname",
    []
);
$facilityNames = array_column($facilities, 'name', 'id');
$providerNames = array_column($providers, 'name', 'id');

// ERX or EPCS, as set under Administration, Users. A user with no profile row
// is ERX, as ProviderProfileStore reads it. An install upgraded in place may
// not have the table yet.
$prescribingTypes = QueryUtils::existsTable(ProviderProfileStore::TABLE)
    ? array_column(
        QueryUtils::fetchRecords('SELECT user_id, prescribing_type FROM ' . ProviderProfileStore::TABLE, []),
        'prescribing_type',
        'user_id'
    )
    : [];

// The default facility must be one that actually has a credential. Defaulting
// to the alphabetically first facility pointed the registration steps at a
// facility with no credential, which then failed with a message that read as
// though nothing had been saved at all.
$credentialFacilityIds = [];
foreach ($credentials as $c) {
    if (!empty($c['active']) && $c['scope_type'] === CredentialStore::SCOPE_PRACTICE) {
        $credentialFacilityIds[] = (int)$c['facility_id'];
    }
}

// One facility is set up at a time. It is picked once, at the top of the
// screen, and every per-facility form carries it, so the credential shown and
// the registration steps can never point at different facilities. The ID.me
// return names its facility the same way.
// An id that names no facility is ignored, or the picker would show one
// facility while the steps below acted on another.
$requestedFacility = (int)($_POST['facility_id'] ?? 0) ?: (int)($_GET['facility_id'] ?? 0);
if (!isset($facilityNames[$requestedFacility])) {
    $requestedFacility = 0;
}
$selectedFacility = $requestedFacility
    ?: ($credentialFacilityIds[0] ?? (int)($facilities[0]['id'] ?? 0));
$selectedFacilityName = (string)($facilityNames[$selectedFacility] ?? '');

// Switching facility is a plain GET back to this screen; a non-default site
// has to stay named.
$facilityUrl = static function (int $facilityId): string {
    $query = ['facility_id' => $facilityId];
    if (!empty($_GET['site'])) {
        $query['site'] = (string)$_GET['site'];
    }
    return '?' . http_build_query($query);
};

// Re-show what is stored for that facility. Leaving the form blank after a save
// made a successful save look like a no-op. The key is deliberately absent —
// it is never rendered back.
$editing = $selectedFacility > 0 ? $store->getCredential($selectedFacility) : null;

$config = new GlobalConfig($GLOBALS);

// Where each step stands, worked out once: the overview, the step headers and
// the registration buttons all read from here.
$connected = $config->getBackendBaseUrl() !== '' && $config->getBackendTokenUrl() !== '';
$hasKey = $editing !== null && !empty($editing['signing_key_path']);
$registrationUuid = trim((string)($editing['erx_client_registration_uuid'] ?? ''))
    ?: $config->getErxClientRegistrationUuid();
$credentialReady = $editing !== null && $hasKey && $registrationUuid !== '';

// Report every missing prerequisite at once. Each of these otherwise surfaces
// one at a time, as a failure after the click, which reads as the button not
// working.
$blockers = [];
if ($editing === null) {
    $blockers[] = xl('a saved credential for this facility (step 2)');
} elseif (!$hasKey) {
    $blockers[] = xl('a private key on that credential (step 2)');
}
if ($registrationUuid === '') {
    $blockers[] = xl('the eRx client registration UUID (step 2)');
}
if ($config->getBackendBaseUrl() === '') {
    $blockers[] = xl('the backend base URL (step 1)');
}
if ($config->getBackendTokenUrl() === '') {
    $blockers[] = xl('the backend token URL (step 1)');
}
$hasPractice = CredentialInput::normalizeProvisionedUuid($editing['erx_practice_uuid'] ?? null) !== null;
$hasLocation = CredentialInput::normalizeProvisionedUuid($editing['erx_location_uuid'] ?? null) !== null;

$registered = [];
foreach ($credentials as $c) {
    if (
        (int)$c['facility_id'] === $selectedFacility
        && $c['scope_type'] === CredentialStore::SCOPE_PROVIDER
        && !empty($c['active'])
        && ProvisioningService::clinicianIdentifiers($c) !== null
    ) {
        $registered[] = (int)$c['provider_user_id'];
    }
}

// ID.me standing of every prescriber registered at this facility. An install
// upgraded in place may not have the table yet.
$idmeTableReady = QueryUtils::existsTable(IdmeVerificationStore::TABLE);
$idmeRows = $idmeTableReady
    ? (new IdmeVerificationStore())->listForFacility($selectedFacility)
    : [];
$verifiedCount = 0;
foreach ($registered as $providerId) {
    $standing = IdmeVerificationService::standing($idmeRows[$providerId] ?? null);
    if ($standing['status'] === IdmeVerificationService::STATUS_VERIFIED) {
        $verifiedCount++;
    }
}

$stepState = [
    'connect' => $connected ? 'done' : 'todo',
    'credential' => $credentialReady ? 'done' : ($editing !== null ? 'partial' : 'todo'),
    'register' => ($hasPractice && $hasLocation && $registered !== [])
        ? 'done'
        : ($hasPractice ? 'partial' : 'todo'),
    'verify' => $registered === []
        ? 'todo'
        : ($verifiedCount === count($registered) ? 'done' : 'partial'),
];
// The earliest unfinished step is the one to do next, whatever its state.
$nextStep = null;
foreach ($stepState as $step => $state) {
    if ($state !== 'done') {
        $nextStep = $step;
        break;
    }
}

$stepSummary = [
    'connect' => $connected
        ? (string)(parse_url($config->getBackendBaseUrl(), PHP_URL_HOST) ?: $config->getBackendBaseUrl())
        : xl('Backend addresses not set'),
    'credential' => $credentialReady
        ? xl('Client ID and private key saved')
        : ($editing !== null ? xl('Key or registration UUID missing') : xl('No credential yet')),
    'register' => ($hasPractice && $hasLocation)
        ? count($registered) . ' ' . xl('prescriber(s) registered')
        : ($hasPractice ? xl('Practice done, location next') : xl('Not registered')),
    'verify' => $registered === []
        ? xl('Register a prescriber first')
        : $verifiedCount . ' / ' . count($registered) . ' ' . xl('verified with ID.me'),
];

// The address LogiCoy is given. site_addr_oath is the externally reachable
// origin OpenEMR already uses for OAuth2; a non-default site has to be named.
$webhookUrl = rtrim((string)($GLOBALS['site_addr_oath'] ?? ''), '/')
    . rtrim((string)($GLOBALS['webroot'] ?? ''), '/')
    . '/interface/modules/custom_modules/oe-module-logicoy-erx/public/webhook.php';
$siteId = SessionContext::getString('site_id');
if ($siteId !== '' && $siteId !== 'default') {
    $webhookUrl .= '?site=' . rawurlencode($siteId);
}

// The background service that sends the queue and polls for notifications.
// An upgrade that only replaced the files never ran the Manage Modules step
// that adds it, so put it back here while the module is enabled.
$syncStatus = null;
try {
    $syncStatus = BackgroundSync::ensureRegistered();
} catch (Throwable $e) {
    (new \OpenEMR\Common\Logging\SystemLogger())->error('LogiCoy eRx background sync status unavailable', [
        'error' => $e->getMessage(),
    ]);
}

// Warn before an upgrade quietly deletes the key rather than after.
$keyStore = new KeyStore();
$globalKeyPath = $config->getErxSigningKeyPath();
$ephemeral = [];
foreach ($credentials as $c) {
    if (!empty($c['signing_key_path']) && $keyStore->isEphemeralInContainer($c['signing_key_path'])) {
        $ephemeral[] = $c['signing_key_path'];
    }
}
if ($globalKeyPath !== '' && $keyStore->isEphemeralInContainer($globalKeyPath)) {
    $ephemeral[] = $globalKeyPath;
}

// The settings are edited where they belong rather than as one long list. A
// setting not named here still appears, under Advanced.
$settingDefs = GlobalConfig::getModuleScreenSettings();
$settingGroups = [
    'connection' => [GlobalConfig::BACKEND_BASE_URL, GlobalConfig::BACKEND_TOKEN_URL],
    'inbound' => [GlobalConfig::POLL_ENABLED, GlobalConfig::WEBHOOK_ENABLED],
    'prescribing' => [
        GlobalConfig::WRITE_TO_PRESCRIPTIONS,
        GlobalConfig::REPLACE_RX_BUTTONS,
        GlobalConfig::FILL_INDICATOR,
    ],
];
$settingGroups['advanced'] = array_values(array_diff(
    array_keys($settingDefs),
    array_merge(...array_values($settingGroups))
));

$renderSetting = static function (string $key) use ($settingDefs): void {
    if (!isset($settingDefs[$key])) {
        return;
    }
    [$label, $type, $default, $help] = $settingDefs[$key];
    $current = $GLOBALS[$key] ?? $default;
    $id = 'setting_' . $key;
    if ($type === 'bool') : ?>
        <div class="custom-control custom-switch mb-3">
            <input type="hidden" name="setting[<?php echo attr($key); ?>]" value="0">
            <input type="checkbox" class="custom-control-input" id="<?php echo attr($id); ?>"
                   name="setting[<?php echo attr($key); ?>]" value="1"
                <?php echo !empty($current) ? 'checked' : ''; ?>>
            <label class="custom-control-label" for="<?php echo attr($id); ?>"><?php echo text($label); ?></label>
            <small class="form-text text-muted mt-0"><?php echo text($help); ?></small>
        </div>
    <?php elseif ($type === 'select') : ?>
        <div class="form-group">
            <label class="mb-1" for="<?php echo attr($id); ?>"><?php echo text($label); ?></label>
            <select class="form-control" id="<?php echo attr($id); ?>" name="setting[<?php echo attr($key); ?>]">
                <?php
                $options = GlobalConfig::getSettingOptions($key);
                // A stored value the list no longer has falls back to the
                // default, so the form never shows a blank choice.
                $selected = isset($options[(string)$current]) ? (string)$current : $default;
                foreach ($options as $value => $optionLabel) : ?>
                    <option value="<?php echo attr((string)$value); ?>"
                        <?php echo (string)$value === $selected ? 'selected' : ''; ?>>
                        <?php echo text($optionLabel); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small class="form-text text-muted"><?php echo text($help); ?></small>
        </div>
    <?php else : ?>
        <div class="form-group">
            <label class="mb-1" for="<?php echo attr($id); ?>"><?php echo text($label); ?></label>
            <input type="<?php echo $type === 'num' ? 'number' : 'text'; ?>"
                   class="form-control" id="<?php echo attr($id); ?>"
                   name="setting[<?php echo attr($key); ?>]"
                <?php echo $type === 'num' ? 'min="1"' : 'spellcheck="false"'; ?>
                   value="<?php echo attr((string)$current); ?>">
            <small class="form-text text-muted"><?php echo text($help); ?></small>
        </div>
    <?php endif;
};

$stepBadge = static function (string $state): void {
    [$class, $label] = [
        'done' => ['success', xl('Done')],
        'partial' => ['warning', xl('In progress')],
    ][$state] ?? ['light border', xl('Not started')];
    echo '<span class="badge badge-' . attr($class) . ' ml-auto">' . text($label) . '</span>';
};

// [badge colour, label] for a prescriber's ID.me standing, as steps 3 and 4 show it.
$idmeBadge = static fn(?array $row): array => [
    IdmeVerificationService::STATUS_VERIFIED => ['success', xl('Verified')],
    IdmeVerificationService::STATUS_EXPIRED => ['danger', xl('Expired')],
    IdmeVerificationService::STATUS_PENDING => ['warning', xl('Started, not confirmed')],
][IdmeVerificationService::standing($row)['status']] ?? ['secondary', xl('Not verified')];

$stepCircle = static fn(string $state): string => [
    'done' => 'bg-success text-white',
    'partial' => 'bg-warning',
][$state] ?? 'bg-secondary text-white';

$stepHeader = static function (
    int $number,
    string $step,
    string $title,
    string $subtitle = ''
) use (
    $stepState,
    $stepBadge,
    $stepCircle
): void {
    $state = $stepState[$step];
    $circle = $stepCircle($state);
    ?>
    <div class="card-header d-flex align-items-center py-2">
        <span class="erx-step-num <?php echo attr($circle); ?>">
            <?php echo text((string)$number); ?>
        </span>
        <div>
            <strong><?php echo text($title); ?></strong>
            <?php if ($subtitle !== '') : ?>
                <span class="text-muted">— <?php echo text($subtitle); ?></span>
            <?php endif; ?>
        </div>
        <?php $stepBadge($state); ?>
    </div>
    <?php
};
?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(); ?>
    <title><?php echo xlt('LogiCoy eRx Setup'); ?></title>
    <?php // Served from the module root (moduleConfig.php), so the assets are under public/. ?>
    <link rel="stylesheet" href="<?php echo attr('public/' . Bootstrap::assetUrl('erx-modal.css')); ?>">
    <script src="<?php echo attr('public/' . Bootstrap::assetUrl('erx-confirm.js')); ?>"></script>
    <style>
        .erx-step-num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            width: 1.75rem;
            height: 1.75rem;
            margin-right: .6rem;
            border-radius: 50%;
            font-size: .85rem;
            font-weight: 600;
        }
        .erx-overview-step {
            flex: 1 1 12rem;
            color: inherit;
            border: 1px solid transparent;
            border-radius: .35rem;
        }
        .erx-overview-step:hover { text-decoration: none; color: inherit; background: rgba(0, 0, 0, .03); }
        .erx-overview-step.erx-next { border-color: var(--primary, #007bff); }
        .erx-section-title {
            margin: 1.75rem 0 .75rem;
            font-size: .8rem;
            font-weight: 600;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--gray, #6c757d);
        }
        .erx-reg-step + .erx-reg-step { border-top: 1px solid rgba(0, 0, 0, .08); }
        .erx-uuid { font-size: .8rem; word-break: break-all; }
        /* Scrolls past 20rem. Once there is more than one page the height is
           fixed, so paging to a short last page does not shift the screen. */
        .erx-table-scroll { max-height: 20rem; overflow-y: auto; }
        .erx-table-scroll.erx-paged { height: 20rem; }
        .erx-table-scroll thead th { position: sticky; top: 0; z-index: 1; border-top: 0; }
    </style>
</head>
<body class="body_top">
<div class="container-fluid p-3">
    <div class="d-flex flex-wrap align-items-baseline mb-2">
        <h4 class="mb-1 mr-3"><?php echo xlt('LogiCoy eRx Setup'); ?></h4>
        <span class="text-muted small">
            <?php echo xlt('Work through the steps in order. Step 1 applies to the whole site; steps 2 to 4 are done once for each facility.'); ?>
        </span>
    </div>

    <?php foreach ($messages as $m) : ?>
        <div class="alert alert-success py-2"><?php echo text($m); ?></div>
    <?php endforeach; ?>
    <?php if ($errors !== []) : ?>
        <div class="alert alert-danger py-2">
            <ul class="mb-0 pl-3">
                <?php foreach ($errors as $e) : ?>
                    <li><?php echo text($e); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php // Overview: the facility being set up, and where each step stands. ?>
    <div class="card mb-3">
        <div class="card-body py-3">
            <form method="get" class="form-inline mb-3">
                <?php if (!empty($_GET['site'])) : ?>
                    <input type="hidden" name="site" value="<?php echo attr((string)$_GET['site']); ?>">
                <?php endif; ?>
                <label class="mr-2 font-weight-bold" for="facility_switch"><?php echo xlt('Facility'); ?></label>
                <select class="form-control form-control-sm mr-2" id="facility_switch" name="facility_id"
                        onchange="this.form.submit();">
                    <?php foreach ($facilities as $f) : ?>
                        <option value="<?php echo attr((string)$f['id']); ?>"
                            <?php echo (int)$f['id'] === $selectedFacility ? 'selected' : ''; ?>>
                            <?php echo text($f['name']); ?>
                            <?php echo in_array((int)$f['id'], $credentialFacilityIds, true)
                                ? '— ' . text(xl('set up')) : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="btn btn-sm btn-secondary"><?php echo xlt('Switch'); ?></button></noscript>
                <small class="text-muted"><?php echo xlt('Steps 2 to 4 below are for this facility.'); ?></small>
            </form>

            <div class="d-flex flex-wrap" style="gap: .5rem;">
                <?php
                $overview = [
                    'connect' => xl('Connect to LogiCoy'),
                    'credential' => xl('Facility credential'),
                    'register' => xl('Register with LogiCoy'),
                    'verify' => xl('Verify prescribers'),
                ];
                $n = 0;
                ?>
                <?php foreach ($overview as $step => $title) : ?>
                    <?php
                    $n++;
                    $state = $stepState[$step];
                    $circle = $stepCircle($state);
                    ?>
                    <a href="#step-<?php echo attr($step); ?>"
                       class="erx-overview-step d-flex align-items-start p-2 <?php echo $step === $nextStep ? 'erx-next' : ''; ?>">
                        <span class="erx-step-num <?php echo attr($circle); ?>">
                            <?php echo text((string)$n); ?>
                        </span>
                        <span>
                            <span class="d-block font-weight-bold small">
                                <?php echo text($title); ?>
                                <?php if ($step === $nextStep) : ?>
                                    <span class="badge badge-primary ml-1"><?php echo xlt('Next'); ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="d-block text-muted small"><?php echo text($stepSummary[$step]); ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php // Step 1: site-wide connection. ?>
    <div class="card mb-3" id="step-connect">
        <?php $stepHeader(1, 'connect', xl('Connect to LogiCoy'), xl('whole site')); ?>
        <div class="card-body">
            <p class="text-muted small"><?php echo xlt('The LogiCoy API addresses. LogiCoy gives you these; they are the same for every facility.'); ?></p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo attr($csrfToken); ?>">
                <input type="hidden" name="action" value="save-settings">
                <input type="hidden" name="facility_id" value="<?php echo attr((string)$selectedFacility); ?>">
                <div class="form-row">
                    <?php foreach ($settingGroups['connection'] as $key) : ?>
                        <div class="col-lg-6"><?php $renderSetting($key); ?></div>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn btn-primary"><?php echo xlt('Save connection'); ?></button>
            </form>
        </div>
    </div>

    <?php // Step 2: the facility's credential. ?>
    <div class="card mb-3" id="step-credential">
        <?php $stepHeader(2, 'credential', xl('Facility credential'), $selectedFacilityName); ?>
        <div class="card-body">
            <?php if ($ephemeral !== []) : ?>
                <div class="alert alert-warning py-2">
                    <strong><?php echo xlt('This key will be lost when the container is rebuilt.'); ?></strong>
                    <div class="small mt-1">
                        <?php echo xlt('OpenEMR is running in a container and the private key is stored outside the mounted sites volume:'); ?>
                        <ul class="mb-1">
                            <?php foreach (array_unique($ephemeral) as $path) : ?>
                                <li><code><?php echo text($path); ?></code></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php echo xlt('Upload the key below instead. It will be written to the certificates directory inside the sites volume, which survives a container recreate.'); ?>
                    </div>
                </div>
            <?php endif; ?>

            <p class="text-muted small">
                <?php if ($editing !== null) : ?>
                    <?php echo xlt('A credential is saved for this facility. Saving again updates it; leave the key empty to keep the stored one.'); ?>
                <?php else : ?>
                    <?php echo xlt('Enter the client ID and registration UUID for this facility, and the private key generated in the LogiCoy Vendor portal.'); ?>
                <?php endif; ?>
            </p>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo attr($csrfToken); ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="facility_id" value="<?php echo attr((string)$selectedFacility); ?>">

                <div class="form-row">
                    <div class="form-group col-lg-6">
                        <label class="mb-1" for="client_id"><?php echo xlt('Client ID'); ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control text-monospace" id="client_id" name="client_id" required
                               spellcheck="false" value="<?php echo attr((string)($editing['client_id'] ?? '')); ?>">
                    </div>
                    <div class="form-group col-lg-6">
                        <label class="mb-1" for="erx_client_registration_uuid">
                            <?php echo xlt('eRx client registration UUID'); ?> <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control text-monospace" spellcheck="false"
                               id="erx_client_registration_uuid" name="erx_client_registration_uuid"
                               value="<?php echo attr((string)($editing['erx_client_registration_uuid'] ?? '')); ?>">
                        <small class="form-text text-muted">
                            <?php echo xlt('Issued by LogiCoy alongside the client ID. Needed to register the practice.'); ?>
                        </small>
                    </div>
                </div>

                <div class="form-group">
                    <label class="mb-1 d-block" for="signing_key">
                        <?php echo xlt('Private key'); ?>
                        <?php if ($hasKey) : ?>
                            <span class="badge badge-success ml-1"><?php echo xlt('Stored'); ?></span>
                        <?php else : ?>
                            <span class="text-danger">*</span>
                            <span class="badge badge-danger ml-1"><?php echo xlt('Not stored'); ?></span>
                        <?php endif; ?>
                    </label>
                    <input type="file" class="form-control-file" id="signing_key" name="signing_key"
                           accept=".key,.pem,.txt">
                    <small class="form-text text-muted">
                        <?php echo xlt('Generate the private key in the LogiCoy Vendor portal and upload the PEM file it gives you. It is encrypted and kept in the site certificates folder, where the web server denies direct access, and is never shown again.'); ?>
                        <?php if ($hasKey) : ?>
                            <?php echo xlt('Choose a file only to replace the stored key.'); ?>
                        <?php endif; ?>
                    </small>
                </div>

                <p class="mb-2">
                    <a class="small" data-toggle="collapse" href="#credential-more" role="button"
                       aria-expanded="false" aria-controls="credential-more">
                        <?php echo xlt('More options: paste the key, use a key on the server, or a prescriber-only credential'); ?>
                    </a>
                </p>

                <div class="collapse" id="credential-more">
                    <div class="border rounded p-3 mb-3">
                        <div class="form-group">
                            <label class="mb-1" for="signing_key_text"><?php echo xlt('Paste the key instead of uploading it'); ?></label>
                            <textarea class="form-control text-monospace" id="signing_key_text" name="signing_key_text"
                                      rows="4" spellcheck="false" autocomplete="off"
                                      placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----"></textarea>
                        </div>

                        <div class="form-group">
                            <label class="mb-1" for="signing_key_path"><?php echo xlt('Or reference a key already on the server'); ?></label>
                            <input type="text" class="form-control text-monospace" id="signing_key_path"
                                   name="signing_key_path" placeholder="/etc/logicoy/partner_private.key">
                            <small class="form-text text-muted">
                                <?php echo xlt('For keys placed by configuration management on a bare metal or VM install. Not suitable for Docker — a path inside the container is deleted when the container is rebuilt.'); ?>
                            </small>
                        </div>

                        <div class="form-row mb-0">
                            <div class="form-group col-md-5 mb-0">
                                <label class="mb-1" for="scope_type"><?php echo xlt('Credential applies to'); ?></label>
                                <select class="form-control" id="scope_type" name="scope_type">
                                    <option value="practice"><?php echo xlt('The whole facility (usual)'); ?></option>
                                    <option value="provider"><?php echo xlt('One prescriber only'); ?></option>
                                </select>
                            </div>
                            <div class="form-group col-md-7 mb-0">
                                <label class="mb-1" for="provider_user_id"><?php echo xlt('Prescriber'); ?></label>
                                <select class="form-control" id="provider_user_id" name="provider_user_id">
                                    <option value=""><?php echo xlt('-- none --'); ?></option>
                                    <?php foreach ($providers as $p) : ?>
                                        <option value="<?php echo attr((string)$p['id']); ?>"><?php echo text($p['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">
                                    <?php echo xlt('Only when LogiCoy issued this prescriber their own client ID and key. Registering a prescriber in step 3 does not need this.'); ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary"><?php echo xlt('Save credential'); ?></button>
            </form>
        </div>
    </div>

    <?php // Step 3: registration, which hands back the LogiCoy identifiers. ?>
    <div class="card mb-3" id="step-register">
        <?php $stepHeader(3, 'register', xl('Register with LogiCoy'), $selectedFacilityName); ?>
        <div class="card-body">
            <p class="text-muted small">
                <?php echo xlt('LogiCoy issues an identifier for the practice, its service location and each prescriber. Run each once, in order — there is nothing to type in.'); ?>
            </p>

            <?php if ($blockers !== []) : ?>
                <div class="alert alert-warning py-2">
                    <strong><?php echo xlt('Registration cannot reach LogiCoy yet. Still missing:'); ?></strong>
                    <ul class="mb-0 mt-1 pl-3">
                        <?php foreach ($blockers as $blocker) : ?>
                            <li><?php echo text($blocker); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="border rounded">
                <form method="post" class="mb-0">
                    <input type="hidden" name="csrf_token" value="<?php echo attr($csrfToken); ?>">
                    <input type="hidden" name="facility_id" value="<?php echo attr((string)$selectedFacility); ?>">

                    <?php
                    $regRows = [
                        ['provision-practice', xl('Practice'), $hasPractice, $editing['erx_practice_uuid'] ?? '', $blockers === []],
                        ['provision-location', xl('Service location'), $hasLocation, $editing['erx_location_uuid'] ?? '', $blockers === [] && $hasPractice],
                    ];
                    ?>
                    <?php foreach ($regRows as $i => [$action, $label, $done, $uuid, $ready]) : ?>
                        <div class="erx-reg-step d-flex align-items-center p-2">
                            <span class="erx-step-num <?php echo $done ? 'bg-success text-white' : 'bg-light border'; ?>">
                                <?php echo $done ? '&#10003;' : text(chr(ord('a') + $i)); ?>
                            </span>
                            <div class="mr-2">
                                <strong><?php echo text($label); ?></strong>
                                <?php if ($done) : ?>
                                    <div class="text-muted erx-uuid"><code><?php echo text((string)$uuid); ?></code></div>
                                <?php elseif (!$ready && $blockers === []) : ?>
                                    <div class="text-muted small"><?php echo xlt('Register the practice first.'); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="ml-auto">
                                <?php if ($done) : ?>
                                    <span class="badge badge-success"><?php echo xlt('Registered'); ?></span>
                                <?php else : ?>
                                    <button class="btn btn-sm btn-primary" name="action" value="<?php echo attr($action); ?>"
                                        <?php echo $ready ? '' : 'disabled'; ?>>
                                        <?php echo xlt('Register'); ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </form>

                <?php
                // Every OpenEMR user who could prescribe, with where each stands
                // at LogiCoy, so registering is a click on the row rather than a
                // pick from a list that hides who is already done.
                $canRegisterPrescribers = $hasLocation && $blockers === [];
                ?>
                <div class="erx-reg-step p-2">
                    <div class="d-flex flex-wrap align-items-center mb-2" style="gap: .5rem;">
                        <span class="erx-step-num mr-0 <?php echo $registered !== [] ? 'bg-success text-white' : 'bg-light border'; ?>">
                            <?php echo $registered !== [] ? '&#10003;' : 'c'; ?>
                        </span>
                        <strong><?php echo xlt('Prescribers'); ?></strong>
                        <span class="text-muted small">
                            <?php echo text(count($registered) . ' / ' . count($providers) . ' ' . xl('registered')); ?>
                        </span>
                        <input type="search" class="form-control form-control-sm ml-auto" id="prescriber_filter"
                               style="max-width: 15rem;" autocomplete="off"
                               placeholder="<?php echo xla('Search name or NPI'); ?>"
                               aria-label="<?php echo xla('Search prescribers'); ?>">
                    </div>
                    <?php if (!$canRegisterPrescribers) : ?>
                        <p class="small text-muted mb-2">
                            <?php echo xlt('Register the practice and service location above before registering prescribers.'); ?>
                        </p>
                    <?php endif; ?>

                    <div class="erx-table-scroll border rounded">
                        <table class="table table-sm table-hover mb-0" id="prescriber_table">
                            <thead class="thead-light">
                            <tr>
                                <th><?php echo xlt('Prescriber'); ?></th>
                                <th><?php echo xlt('NPI'); ?></th>
                                <th><?php echo xlt('Type'); ?></th>
                                <th><?php echo xlt('LogiCoy'); ?></th>
                                <th><?php echo xlt('ID.me'); ?></th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($providers as $prov) : ?>
                                <?php
                                $provId = (int)$prov['id'];
                                $npi = trim((string)($prov['npi'] ?? ''));
                                $isRegistered = in_array($provId, $registered, true);
                                $type = ($prescribingTypes[$provId] ?? '') === ProviderProfileInput::TYPE_EPCS
                                    ? ProviderProfileInput::TYPE_EPCS
                                    : ProviderProfileInput::TYPE_ERX;
                                ?>
                                <tr data-search="<?php echo attr(strtolower($prov['name'] . ' ' . $npi)); ?>">
                                    <td class="align-middle"><?php echo text($prov['name']); ?></td>
                                    <td class="align-middle">
                                        <?php if ($npi !== '') : ?>
                                            <span class="text-monospace small"><?php echo text($npi); ?></span>
                                        <?php else : ?>
                                            <span class="badge badge-light border"><?php echo xlt('None'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="align-middle small"><?php echo text($type); ?></td>
                                    <td class="align-middle">
                                        <?php if ($isRegistered) : ?>
                                            <span class="badge badge-success"><?php echo xlt('Registered'); ?></span>
                                        <?php else : ?>
                                            <span class="badge badge-light border"><?php echo xlt('Not registered'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="align-middle">
                                        <?php if ($isRegistered) : ?>
                                            <?php $badge = $idmeBadge($idmeRows[$provId] ?? null); ?>
                                            <a href="#step-verify" class="badge badge-<?php echo attr($badge[0]); ?>"
                                               title="<?php echo xla('See step 4'); ?>"><?php echo text($badge[1]); ?></a>
                                        <?php else : ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="align-middle text-right">
                                        <?php if (!$isRegistered) : ?>
                                            <form method="post" class="mb-0">
                                                <input type="hidden" name="csrf_token" value="<?php echo attr($csrfToken); ?>">
                                                <input type="hidden" name="facility_id" value="<?php echo attr((string)$selectedFacility); ?>">
                                                <input type="hidden" name="provider_user_id" value="<?php echo attr((string)$provId); ?>">
                                                <button class="btn btn-sm btn-outline-primary text-nowrap" name="action"
                                                        value="provision-clinician"
                                                    <?php if ($npi === '') : ?>
                                                        disabled title="<?php echo xla('Add an NPI under Administration, Users first.'); ?>"
                                                    <?php elseif (!$canRegisterPrescribers) : ?>
                                                        disabled
                                                    <?php endif; ?>>
                                                    <?php echo xlt('Register'); ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="text-muted small p-2 mb-0 d-none" id="prescriber_empty">
                            <?php echo xlt('No prescribers match.'); ?>
                        </p>
                    </div>

                    <div id="prescriber_pager">
                        <div class="d-flex flex-wrap align-items-center mt-2" style="gap: .5rem;">
                            <span class="small text-muted" id="prescriber_range"></span>
                            <div class="btn-group btn-group-sm ml-auto">
                                <button type="button" class="btn btn-outline-secondary" id="prescriber_prev">
                                    &lsaquo; <?php echo xlt('Previous'); ?>
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="prescriber_next">
                                    <?php echo xlt('Next'); ?> &rsaquo;
                                </button>
                            </div>
                        </div>
                    </div>

                    <small class="form-text text-muted">
                        <?php echo xlt('Needs an NPI on the user record. Register each prescriber once.'); ?>
                        <?php echo xlt('A prescriber set to EPCS also needs their date of birth, DEA number, DEA expiration date, DEA state and SSN last 4 under Administration, Users.'); ?>
                    </small>
                </div>
            </div>
            <script>
                // Client-side paging and search over the prescriber rows. Every
                // row is in the page already, so nothing is fetched.
                (function () {
                    const pageSize = 10;
                    const rows = Array.from(document.querySelectorAll('#prescriber_table tbody tr'));
                    const filter = document.getElementById('prescriber_filter');
                    const range = document.getElementById('prescriber_range');
                    const prev = document.getElementById('prescriber_prev');
                    const next = document.getElementById('prescriber_next');
                    const pager = document.getElementById('prescriber_pager');
                    const empty = document.getElementById('prescriber_empty');
                    const scroller = document.querySelector('.erx-table-scroll');
                    const ofLabel = <?php echo js_escape(xl('of')); ?>;
                    let page = 0;

                    function render() {
                        const q = filter.value.trim().toLowerCase();
                        const matching = rows.filter(r => q === '' || r.dataset.search.includes(q));
                        const pages = Math.max(1, Math.ceil(matching.length / pageSize));
                        page = Math.min(page, pages - 1);
                        const start = page * pageSize;
                        rows.forEach(r => { r.style.display = 'none'; });
                        matching.slice(start, start + pageSize).forEach(r => { r.style.display = ''; });
                        empty.classList.toggle('d-none', matching.length > 0);
                        range.textContent = matching.length === 0
                            ? ''
                            : (start + 1) + '–' + Math.min(start + pageSize, matching.length) + ' ' + ofLabel + ' ' + matching.length;
                        prev.disabled = page === 0;
                        next.disabled = page >= pages - 1;
                        pager.style.display = matching.length <= pageSize ? 'none' : '';
                        scroller.classList.toggle('erx-paged', matching.length > pageSize);
                        scroller.scrollTop = 0;
                    }

                    // Nothing to search or page through when it all fits on one page.
                    if (rows.length <= pageSize) {
                        filter.style.display = 'none';
                    }
                    filter.addEventListener('input', () => { page = 0; render(); });
                    prev.addEventListener('click', () => { page--; render(); });
                    next.addEventListener('click', () => { page++; render(); });
                    render();
                })();
            </script>
        </div>
    </div>

    <?php // Step 4: ID.me and EPCS for the prescribers registered above. ?>
    <div class="card mb-3" id="step-verify">
        <?php $stepHeader(4, 'verify', xl('Verify prescribers with ID.me'), $selectedFacilityName); ?>
        <div class="card-body">
            <?php if ($registered === []) : ?>
                <p class="text-muted mb-0"><?php echo xlt('Register a prescriber in step 3 and they will appear here.'); ?></p>
            <?php else : ?>
                <p class="text-muted small mb-2">
                    <?php echo xlt('Each prescriber proves their identity with their own ID.me account. Enter that account\'s email and press Verify: ID.me opens in a new tab, the prescriber signs in, and the tab comes back here with the result.'); ?>
                    <?php echo xlt('Prescribers can also do this themselves under Miscellaneous, LogiCoy eRx, ID.me Verification.'); ?>
                    <a data-toggle="collapse" href="#verify-help" role="button" aria-expanded="false" aria-controls="verify-help">
                        <?php echo xlt('What do the other actions do?'); ?>
                    </a>
                </p>
                <div class="collapse" id="verify-help">
                    <ul class="small text-muted pl-3">
                        <li><strong><?php echo xlt('Check status'); ?></strong> — <?php echo xlt('asks LogiCoy for the ID.me result again, if ID.me was still finishing.'); ?></li>
                        <li><strong><?php echo xlt('Update with LogiCoy'); ?></strong> — <?php echo xlt('sends the prescriber\'s current details again: DEA number, expiry and state, date of birth, phone, address, and the service levels, including the controlled substance level when the prescriber is set to EPCS.'); ?></li>
                        <li><strong><?php echo xlt('EPCS status'); ?></strong> — <?php echo xlt('shows how LogiCoy holds the prescriber (ERX or EPCS) and where an upgrade stands.'); ?></li>
                        <li><strong><?php echo xlt('Upgrade to EPCS'); ?></strong> — <?php echo xlt('turns a prescriber LogiCoy holds as ERX into an EPCS prescriber: it validates the DEA, registers them for controlled substances and sends the browser to ID.me in the same step. The prescriber must already be set to EPCS here with the DEA fields complete. It cannot be undone.'); ?></li>
                        <li><?php echo xlt('Each prescriber needs their own ID.me account; LogiCoy refuses an email already used by another.'); ?></li>
                    </ul>
                </div>
                <?php if (!$idmeTableReady) : ?>
                    <div class="alert alert-warning py-2">
                        <?php echo xlt('The ID.me table is missing. Disable and re-enable the module to create it.'); ?>
                    </div>
                <?php endif; ?>
                <table class="table table-sm mb-0" id="idme_table">
                    <thead><tr>
                        <th><?php echo xlt('Prescriber'); ?></th>
                        <th><?php echo xlt('ID.me'); ?></th>
                        <th><?php echo xlt('Expires'); ?></th>
                        <th style="min-width: 9rem;"><?php echo xlt('ID.me email'); ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($registered as $providerId) : ?>
                        <?php
                        $row = $idmeRows[$providerId] ?? null;
                        $standing = IdmeVerificationService::standing($row);
                        $badge = $idmeBadge($row);
                        $idmeFormId = 'idme-' . $selectedFacility . '-' . $providerId;
                        ?>
                        <tr class="<?php echo $providerId === $idmeReturnedFor ? 'table-info' : ''; ?>"
                            <?php echo $providerId === $idmeReturnedFor ? 'data-returned="1"' : ''; ?>>
                            <td class="align-middle"><?php echo text((string)($providerNames[$providerId] ?? $providerId)); ?></td>
                            <td class="align-middle">
                                <span class="badge badge-<?php echo attr($badge[0]); ?>"><?php echo text($badge[1]); ?></span>
                                <?php if (!empty($row['checked_at'])) : ?>
                                    <div class="text-muted small text-nowrap"
                                         title="<?php echo attr(xl('Last checked') . ' ' . $row['checked_at']); ?>">
                                        <?php echo xlt('checked'); ?> <?php echo text(substr((string)$row['checked_at'], 0, 10)); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="align-middle text-nowrap <?php echo $standing['expiring_soon'] ? 'text-danger' : ''; ?>">
                                <?php echo text((string)($standing['expires_on'] ?? '-')); ?>
                            </td>
                            <td class="align-middle">
                                <form method="post" id="<?php echo attr($idmeFormId); ?>" class="mb-0">
                                    <input type="hidden" name="csrf_token" value="<?php echo attr($csrfToken); ?>">
                                    <input type="hidden" name="facility_id" value="<?php echo attr((string)$selectedFacility); ?>">
                                    <input type="hidden" name="provider_user_id"
                                           value="<?php echo attr((string)$providerId); ?>">
                                    <input type="email" class="form-control form-control-sm" name="idme_email"
                                           required <?php echo $idmeTableReady ? '' : 'disabled'; ?>
                                           aria-label="<?php echo xla('ID.me email'); ?>"
                                           placeholder="<?php echo xla('prescriber@example.com'); ?>"
                                           value="<?php echo attr((string)($row['idme_email'] ?? '')); ?>">
                                </form>
                            </td>
                            <td class="align-middle">
                                <?php
                                // ID.me refuses to load inside a frame, and this
                                // screen sits in the Manage Modules iframe, so the
                                // redirect is sent to a new tab. ID.me returns to
                                // this screen in that tab.
                                ?>
                                <div class="d-flex flex-wrap justify-content-end" style="gap: .25rem;">
                                <button class="btn btn-sm btn-primary text-nowrap" form="<?php echo attr($idmeFormId); ?>"
                                        name="action" value="idme-initiate" formtarget="_blank"
                                        <?php echo $idmeTableReady ? '' : 'disabled'; ?>>
                                    <?php echo $standing['status'] === IdmeVerificationService::STATUS_VERIFIED
                                        ? xlt('Verify again')
                                        : xlt('Verify with ID.me'); ?>
                                </button>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle"
                                            data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                        <?php echo xlt('More'); ?>
                                    </button>
                                    <div class="dropdown-menu dropdown-menu-right">
                                        <?php if (!empty($row['idme_email'])) : ?>
                                            <button class="dropdown-item" form="<?php echo attr($idmeFormId); ?>"
                                                    name="action" value="idme-check" formnovalidate>
                                                <?php echo xlt('Check ID.me status'); ?>
                                            </button>
                                        <?php endif; ?>
                                        <button class="dropdown-item" form="<?php echo attr($idmeFormId); ?>"
                                                name="action" value="provision-update-clinician" formnovalidate>
                                            <?php echo xlt('Update with LogiCoy'); ?>
                                        </button>
                                        <button class="dropdown-item" form="<?php echo attr($idmeFormId); ?>"
                                                name="action" value="provision-epcs-status" formnovalidate>
                                            <?php echo xlt('EPCS status'); ?>
                                        </button>
                                        <div class="dropdown-divider"></div>
                                        <?php
                                        // The ERX -> EPCS flip at LogiCoy. Needs the ID.me email
                                        // from the field, goes to ID.me in a new tab like Verify,
                                        // and returns here with epcs=1 so the upgrade status is
                                        // asked for as well. Irreversible at LogiCoy.
                                        ?>
                                        <button class="dropdown-item text-danger" form="<?php echo attr($idmeFormId); ?>"
                                                name="action" value="provision-upgrade-epcs" formtarget="_blank"
                                                data-erx-confirm="<?php echo attr(xl(
                                                    'Upgrade this prescriber to EPCS at LogiCoy? This cannot be undone.'
                                                )); ?>"
                                                data-erx-confirm-title="<?php echo xla('Upgrade to EPCS'); ?>"
                                                data-erx-confirm-ok="<?php echo xla('Upgrade to EPCS'); ?>"
                                                data-erx-confirm-cancel="<?php echo xla('Cancel'); ?>"
                                                data-erx-confirm-danger>
                                            <?php echo xlt('Upgrade to EPCS…'); ?>
                                        </button>
                                    </div>
                                </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div id="idme_pager">
                    <div class="d-flex flex-wrap align-items-center mt-2" style="gap: .5rem;">
                        <span class="small text-muted" id="idme_range"></span>
                        <div class="btn-group btn-group-sm ml-auto">
                            <button type="button" class="btn btn-outline-secondary" id="idme_prev">
                                &lsaquo; <?php echo xlt('Previous'); ?>
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="idme_next">
                                <?php echo xlt('Next'); ?> &rsaquo;
                            </button>
                        </div>
                    </div>
                </div>
                <script>
                    // Client-side paging over the ID.me rows, like the
                    // prescriber table above. Opens on the page holding the
                    // prescriber ID.me just returned for, if any.
                    (function () {
                        const pageSize = 10;
                        const rows = Array.from(document.querySelectorAll('#idme_table tbody tr'));
                        const range = document.getElementById('idme_range');
                        const prev = document.getElementById('idme_prev');
                        const next = document.getElementById('idme_next');
                        const pager = document.getElementById('idme_pager');
                        const ofLabel = <?php echo js_escape(xl('of')); ?>;
                        const pages = Math.max(1, Math.ceil(rows.length / pageSize));
                        const returned = rows.findIndex(r => r.dataset.returned === '1');
                        let page = returned >= 0 ? Math.floor(returned / pageSize) : 0;

                        function render() {
                            const start = page * pageSize;
                            rows.forEach((r, i) => { r.style.display = i >= start && i < start + pageSize ? '' : 'none'; });
                            range.textContent = (start + 1) + '–' + Math.min(start + pageSize, rows.length)
                                + ' ' + ofLabel + ' ' + rows.length;
                            prev.disabled = page === 0;
                            next.disabled = page >= pages - 1;
                        }

                        if (rows.length <= pageSize) {
                            pager.style.display = 'none';
                            return;
                        }
                        prev.addEventListener('click', () => { page--; render(); });
                        next.addEventListener('click', () => { page++; render(); });
                        render();
                    })();
                </script>
            <?php endif; ?>
        </div>
    </div>

    <div class="erx-section-title"><?php echo xlt('Options'); ?></div>

    <?php // Inbound traffic: polling and the webhook. ?>
    <div class="card mb-3" id="inbound">
        <div class="card-header d-flex align-items-center py-2">
            <strong><?php echo xlt('Receiving updates from LogiCoy'); ?></strong>
            <?php if ($config->isWebhookEnabled() && !$config->hasWebhookSecret()) : ?>
                <span class="badge badge-warning ml-auto"><?php echo xlt('Webhook on, but no secret'); ?></span>
            <?php elseif ($config->isWebhookEnabled()) : ?>
                <span class="badge badge-success ml-auto"><?php echo xlt('Webhook accepting pushes'); ?></span>
            <?php else : ?>
                <span class="badge badge-light border ml-auto"><?php echo xlt('Webhook off'); ?></span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <p class="text-muted small"><?php echo xlt('Refill requests, change requests and status updates reach OpenEMR by polling, by webhook, or both.'); ?></p>
            <?php // The background sync: nothing to schedule, but say when it last could have run. ?>
            <div class="small mb-3" id="background-sync">
                <?php if ($syncStatus !== null && $syncStatus['active']) : ?>
                    <span class="badge badge-success"><?php echo xlt('Background sync on'); ?></span>
                    <?php echo xlt('Queued sends go out and pharmacy messages are collected every'); ?>
                    <?php echo text((string)$syncStatus['execute_interval']); ?>
                    <?php echo xlt('minutes while anyone is signed in to OpenEMR. No cron entry is needed.'); ?>
                    <span class="text-muted">
                        <?php echo xlt('Next run due'); ?>:
                        <?php echo text(oeFormatDateTime($syncStatus['next_run'])); ?>
                    </span>
                <?php elseif ($syncStatus !== null) : ?>
                    <span class="badge badge-secondary"><?php echo xlt('Background sync off'); ?></span>
                    <?php echo xlt('It switches on when the module is enabled in Manage Modules.'); ?>
                <?php else : ?>
                    <span class="badge badge-warning"><?php echo xlt('Background sync not set up'); ?></span>
                    <?php echo xlt('Disable and Enable the module in Manage Modules to add it.'); ?>
                <?php endif; ?>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo attr($csrfToken); ?>">
                <input type="hidden" name="action" value="save-settings">
                <input type="hidden" name="facility_id" value="<?php echo attr((string)$selectedFacility); ?>">
                <?php foreach ($settingGroups['inbound'] as $key) : ?>
                    <?php $renderSetting($key); ?>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-sm btn-primary"><?php echo xlt('Save'); ?></button>
            </form>

            <hr>
            <h6 class="mb-1"><?php echo xlt('Webhook details for LogiCoy'); ?></h6>
            <p class="small text-muted mb-2">
                <?php echo xlt('Give LogiCoy this URL and the secret. LogiCoy sends the secret as an Authorization: Bearer header; the X-LogiCoy-Webhook-Secret header is also accepted.'); ?>
            </p>
            <div class="form-group mb-2">
                <label class="small mb-1" for="webhook_url"><?php echo xlt('Webhook URL'); ?></label>
                <div class="input-group input-group-sm">
                    <input type="text" readonly class="form-control text-monospace" id="webhook_url"
                           value="<?php echo attr($webhookUrl); ?>">
                    <div class="input-group-append">
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="var f=document.getElementById('webhook_url');f.select();navigator.clipboard&&navigator.clipboard.writeText(f.value);">
                            <?php echo xlt('Copy'); ?>
                        </button>
                    </div>
                </div>
                <small class="form-text text-muted">
                    <?php echo xlt('The LogiCoy backend must be able to reach this address over HTTPS from the internet.'); ?>
                </small>
            </div>
            <?php if ($newWebhookSecret !== '') : ?>
                <div class="alert alert-warning py-2">
                    <strong><?php echo xlt('Copy this secret now. It will not be shown again.'); ?></strong>
                    <input type="text" readonly class="form-control form-control-sm mt-1 text-monospace"
                           value="<?php echo attr($newWebhookSecret); ?>" onclick="this.select();">
                </div>
            <?php endif; ?>
            <form method="post" class="mb-0 d-flex align-items-center">
                <input type="hidden" name="csrf_token" value="<?php echo attr($csrfToken); ?>">
                <input type="hidden" name="action" value="generate-webhook-secret">
                <input type="hidden" name="facility_id" value="<?php echo attr((string)$selectedFacility); ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary mr-2"
                    <?php if ($config->hasWebhookSecret()) : ?>
                        data-erx-confirm="<?php echo attr(xl(
                            'Replace the webhook secret? LogiCoy will be refused until it is given the new one.'
                        )); ?>"
                        data-erx-confirm-title="<?php echo xla('Replace webhook secret'); ?>"
                        data-erx-confirm-ok="<?php echo xla('Replace secret'); ?>"
                        data-erx-confirm-cancel="<?php echo xla('Cancel'); ?>"
                        data-erx-confirm-danger
                    <?php endif; ?>>
                    <?php echo $config->hasWebhookSecret()
                        ? xlt('Replace webhook secret')
                        : xlt('Generate webhook secret'); ?>
                </button>
                <small class="text-muted">
                    <?php echo $config->hasWebhookSecret()
                        ? xlt('A secret is set. It is never shown again; replace it if it is lost.')
                        : xlt('No secret yet. Every push is refused until one is generated.'); ?>
                </small>
            </form>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-6">
            <div class="card mb-3">
                <div class="card-header py-2"><strong><?php echo xlt('Prescribing'); ?></strong></div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo attr($csrfToken); ?>">
                        <input type="hidden" name="action" value="save-settings">
                        <input type="hidden" name="facility_id" value="<?php echo attr((string)$selectedFacility); ?>">
                        <?php foreach ($settingGroups['prescribing'] as $key) : ?>
                            <?php $renderSetting($key); ?>
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-sm btn-primary"><?php echo xlt('Save'); ?></button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card mb-3">
                <div class="card-header py-2"><strong><?php echo xlt('Advanced'); ?></strong></div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo attr($csrfToken); ?>">
                        <input type="hidden" name="action" value="save-settings">
                        <input type="hidden" name="facility_id" value="<?php echo attr((string)$selectedFacility); ?>">
                        <?php foreach ($settingGroups['advanced'] as $key) : ?>
                            <?php $renderSetting($key); ?>
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-sm btn-primary"><?php echo xlt('Save'); ?></button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php // Every stored credential, across facilities, for support. ?>
    <div class="card mb-3" id="all-credentials">
        <div class="card-header py-2"><strong><?php echo xlt('All saved credentials'); ?></strong></div>
        <div class="card-body p-0">
            <?php if ($credentials === []) : ?>
                <p class="text-muted p-3 mb-0"><?php echo xlt('No credentials saved yet. Start with step 2.'); ?></p>
            <?php else : ?>
                <?php
                // Registering a prescriber creates a provider row with no key of
                // its own, and getSigningKey() falls back to the practice key for
                // the same facility. Reporting that row as "Missing" reads as a
                // broken credential when prescribing works perfectly well.
                $facilitiesWithPracticeKey = [];
                foreach ($credentials as $c) {
                    if ($c['scope_type'] === CredentialStore::SCOPE_PRACTICE && !empty($c['has_signing_key'])) {
                        $facilitiesWithPracticeKey[(int)$c['facility_id']] = true;
                    }
                }
                ?>
                <table class="table table-sm mb-0">
                    <thead>
                    <tr>
                        <th class="pl-3"><?php echo xlt('Facility'); ?></th>
                        <th><?php echo xlt('Applies to'); ?></th>
                        <th><?php echo xlt('Client ID'); ?></th>
                        <th><?php echo xlt('Private key'); ?></th>
                        <th><?php echo xlt('Status'); ?></th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($credentials as $c) : ?>
                        <?php $isPractice = $c['scope_type'] === CredentialStore::SCOPE_PRACTICE; ?>
                        <tr class="<?php echo $c['active'] ? '' : 'text-muted'; ?>">
                            <td class="pl-3 align-middle">
                                <?php echo text((string)($facilityNames[(int)$c['facility_id']] ?? xl('Facility') . ' ' . $c['facility_id'])); ?>
                            </td>
                            <td class="align-middle">
                                <?php echo $isPractice
                                    ? xlt('Whole facility')
                                    : text((string)($providerNames[(int)$c['provider_user_id']] ?? xl('Prescriber') . ' ' . $c['provider_user_id'])); ?>
                            </td>
                            <td class="align-middle"><code class="erx-uuid"><?php echo text($c['client_id']); ?></code></td>
                            <td class="align-middle">
                                <?php if (!empty($c['has_signing_key'])) : ?>
                                    <span class="badge badge-success"><?php echo xlt('Stored'); ?></span>
                                <?php elseif (isset($facilitiesWithPracticeKey[(int)$c['facility_id']])) : ?>
                                    <span class="badge badge-light border"
                                          title="<?php echo attr(xl('Uses the practice key for this facility.')); ?>">
                                        <?php echo xlt('Uses facility key'); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="badge badge-danger"><?php echo xlt('Missing'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="align-middle">
                                <?php echo $c['active']
                                    ? '<span class="badge badge-success">' . xlt('Active') . '</span>'
                                    : '<span class="badge badge-secondary">' . xlt('Inactive') . '</span>'; ?>
                            </td>
                            <td class="text-right text-nowrap pr-3">
                                <?php if ((int)$c['facility_id'] !== $selectedFacility) : ?>
                                    <a class="btn btn-sm btn-link" href="<?php echo attr($facilityUrl((int)$c['facility_id'])); ?>">
                                        <?php echo xlt('Open'); ?>
                                    </a>
                                <?php endif; ?>
                                <?php if ($c['active']) : ?>
                                    <form method="post" class="d-inline"
                                          data-erx-confirm="<?php echo attr(xl(
                                              'Deactivate this credential? Prescribing for that facility will stop working.'
                                          )); ?>"
                                          data-erx-confirm-title="<?php echo xla('Deactivate credential'); ?>"
                                          data-erx-confirm-ok="<?php echo xla('Deactivate'); ?>"
                                          data-erx-confirm-cancel="<?php echo xla('Cancel'); ?>"
                                          data-erx-confirm-danger>
                                        <input type="hidden" name="csrf_token" value="<?php echo attr($csrfToken); ?>">
                                        <input type="hidden" name="action" value="deactivate">
                                        <input type="hidden" name="facility_id" value="<?php echo attr((string)$selectedFacility); ?>">
                                        <input type="hidden" name="credential_id" value="<?php echo attr((string)$c['id']); ?>">
                                        <button class="btn btn-sm btn-outline-danger"><?php echo xlt('Deactivate'); ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <p class="text-muted small mb-0">
        <?php echo xlt('Administration, Globals, LogiCoy eRx holds only the module on/off switch; everything else is set here.'); ?>
    </p>
</div>
</body>
</html>
