<?php

/**
 * Module setup screen. Rendered by Module Manager inside an iframe.
 *
 * Registers the namespace itself so the screen works before the module has been
 * fully loaded by the module system.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use OpenEMR\Core\ModulesClassLoader;
use OpenEMR\Core\OEGlobalsBag;

require_once dirname(__FILE__, 4) . '/globals.php';

$classLoader = new ModulesClassLoader(OEGlobalsBag::getInstance()->getProjectDir());
$classLoader->registerNamespaceIfNotExists('LogiCoy\\ERx\\', __DIR__ . DIRECTORY_SEPARATOR . 'src');

$module_config = 1;

require_once __DIR__ . '/templates/credentials.php';
exit;
