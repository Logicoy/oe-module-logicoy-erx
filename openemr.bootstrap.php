<?php

/**
 * Loaded by the OpenEMR module system. Registers the namespace and wires the
 * module into the event dispatcher.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx;

/**
 * @var \OpenEMR\Core\ModulesClassLoader $classLoader
 * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
 *
 * Both are injected by the OpenEMR module loader.
 */

$classLoader->registerNamespaceIfNotExists('LogiCoy\\ERx\\', __DIR__ . DIRECTORY_SEPARATOR . 'src');

$bootstrap = new Bootstrap($eventDispatcher);
$bootstrap->subscribeToEvents();
