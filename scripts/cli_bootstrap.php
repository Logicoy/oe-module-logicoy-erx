<?php

/**
 * Shared CLI bootstrap.
 *
 * interface/globals.php resolves the site from $_GET['site'] or HTTP_HOST and
 * aborts with "Invalid URL" when neither is present, which is always the case
 * under CLI. Set both before loading it.
 *
 * Include this instead of globals.php from any command line entry point.
 * Accepts --site=NAME, defaulting to "default".
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$logicoyCliSite = 'default';
foreach ($argv ?? [] as $logicoyCliArg) {
    if (str_starts_with((string)$logicoyCliArg, '--site=')) {
        $logicoyCliSite = substr((string)$logicoyCliArg, 7);
    }
}

// globals.php rejects anything outside this character set anyway; fail early
// with a message that says what is wrong.
if ($logicoyCliSite === '' || preg_match('/[^A-Za-z0-9\-.]/', $logicoyCliSite)) {
    fwrite(STDERR, "Invalid --site value.\n");
    exit(1);
}

$_GET['site'] = $logicoyCliSite;
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';

$ignoreAuth = true;

$logicoyGlobals = __DIR__ . '/../../../../globals.php';

// The relative hop above only resolves when the module sits at
// interface/modules/custom_modules/<module>/. Extracting the archive anywhere
// else otherwise fails with an opaque "failed opening required" fatal.
if (!is_file($logicoyGlobals)) {
    $logicoyActualPath = dirname(__DIR__);
    fwrite(STDERR, <<<TXT
    Cannot find OpenEMR.

    This module must be installed at:
      <openemr>/interface/modules/custom_modules/oe-module-logicoy-erx

    It is currently at:
      {$logicoyActualPath}

    Check the archive was extracted into custom_modules/ and that the folder is
    not nested inside a second folder of the same name.

    TXT);
    exit(1);
}

require_once $logicoyGlobals;

/**
 * openemr.bootstrap.php registers the module namespace, but it only runs for
 * modules the OpenEMR module loader has picked up. A CLI script has to be able
 * to run before the module is registered — that is how credentials get loaded
 * in the first place — so register PSR-4 here directly.
 */
require_once __DIR__ . '/autoload.php';

unset($logicoyCliSite, $logicoyCliArg);
