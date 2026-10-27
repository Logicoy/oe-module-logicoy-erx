<?php

/**
 * Entry point for OpenEMR's background service runner.
 *
 * The background_services row added by LogiCoy\ERx\Queue\BackgroundSync names
 * this file and the function below; OpenEMR includes the file and calls the
 * function every few minutes. Nothing runs on include, so reaching this file
 * over the web does nothing.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use LogiCoy\ERx\Queue\BackgroundSync;

if (!class_exists(BackgroundSync::class)) {
    require_once __DIR__ . '/autoload.php';
}

if (!function_exists('logicoyErxBackgroundSync')) {
    function logicoyErxBackgroundSync(): void
    {
        BackgroundSync::run();
    }
}
