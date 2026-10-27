<?php

/**
 * PHPUnit bootstrap.
 *
 * Unit tests never touch a database and never call the LogiCoy backend. They do
 * need OpenEMR's classes on the autoloader so collaborators can be mocked, so
 * point OPENEMR_ROOT at an OpenEMR checkout with vendor/ installed.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/../vendor/autoload.php';

$openemrRoot = getenv('OPENEMR_ROOT') ?: '/var/www/localhost/htdocs/openemr';

if (!is_file($openemrRoot . '/vendor/autoload.php')) {
    fwrite(
        STDERR,
        "OpenEMR autoloader not found under {$openemrRoot}.\n"
        . "Set OPENEMR_ROOT to an OpenEMR checkout that has vendor/ installed.\n"
    );
    exit(1);
}

require_once $openemrRoot . '/vendor/autoload.php';
