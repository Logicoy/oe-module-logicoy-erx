<?php

/**
 * PSR-4 for the module namespace, for entry points that can run before the
 * OpenEMR module loader has picked the module up: the CLI scripts, and the
 * background service when OpenEMR runs it in a process of its own.
 *
 * Deliberately not requiring the module's vendor/autoload.php: vendor/ holds
 * dev tooling only and is not part of a distributed module.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'LogiCoy\\ERx\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relative . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
