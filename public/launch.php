<?php

/**
 * Prescribing entry point. Opened from the card on Patient Summary.
 *
 * Redirects into the LogiCoy prescribing UI with a SMART EHR launch context.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';

use LogiCoy\ERx\Bootstrap;
use LogiCoy\ERx\Config\GlobalConfig;
use LogiCoy\ERx\Config\SessionContext;
use OpenEMR\Common\Acl\AclMain;

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

if ($config->getBackendBaseUrl() === '') {
    echo xlt('The LogiCoy backend URL is not configured. Set it under Administration, Globals, LogiCoy eRx.');
    exit;
}

/*
 * Prescribing is rendered by this module rather than handed off to a hosted
 * LogiCoy UI: the backend exposes an API, not a SMART launch endpoint. The
 * SMART helper is kept for the day one exists — see docs/HOOKS.md.
 */
header('Location: prescribe.php?pid=' . urlencode((string)$pid));
exit;
