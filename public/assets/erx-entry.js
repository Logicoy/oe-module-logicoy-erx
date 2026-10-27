/**
 * Points OpenEMR's own prescription buttons at the LogiCoy prescribing screen.
 *
 * The Eye Exam form's Impression/Plan "eRx" button, and the Prescriptions
 * card's edit button on the patient dashboard, both call a page-level
 * editScripts(url) that opens core's prescription list in a dialog. This file
 * is added to those pages by the module (ScriptFilterEvent) when the module
 * is on, the setting is on and the user may prescribe. Once the page has
 * loaded it replaces editScripts with one that opens prescribe.php for the
 * same patient in the same kind of dialog, and refreshes the page afterwards
 * as core does. The original stays reachable as logicoyNativeEditScripts.
 *
 * license  https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
(function () {
    'use strict';

    // Resolved from this file's own address: .../public/assets/erx-entry.js.
    // As a path from the site root (it carries the web root when OpenEMR is
    // installed under one), because dlgopen() treats anything that does not
    // start with "/" as relative to the current page.
    var script = document.currentScript;
    var publicBase = '';
    try {
        publicBase = new URL(script.src, location.href).pathname.replace(/assets\/erx-entry\.js.*$/, '');
    } catch (e) {
        publicBase = '';
    }
    if (!publicBase) {
        return;
    }

    function pidFrom(url) {
        var m = /[?&](?:pid|id)=(\d+)/.exec(url || '');
        if (m) {
            return m[1];
        }
        var field = document.getElementById('pid');
        return field && /^\d+$/.test(field.value) ? field.value : '';
    }

    function openLogicoy(url) {
        var pid = pidFrom(url);
        if (top.restoreSession) {
            top.restoreSession();
        }
        var target = publicBase + 'prescribe.php' + (pid ? '?pid=' + encodeURIComponent(pid) : '');
        var closed = dlgopen(target, 'logicoyErx', 'modal-xl', 700, '', '', {
            resolvePromiseOn: 'close',
            allowResize: true,
            allowDrag: true,
            dialogId: 'logicoyerx',
            type: 'iframe'
        });
        if (closed && typeof closed.then === 'function') {
            closed.then(function () {
                // What core's own dialog does on close: the dashboard
                // redraws its cards, a form reloads to show the new script.
                if (typeof window.refreshme === 'function') {
                    window.refreshme();
                } else if (document.getElementById('pid')) {
                    if (top.restoreSession) {
                        top.restoreSession();
                    }
                    location.reload();
                }
            });
        }
        return false;
    }

    window.addEventListener('load', function () {
        // Header::setupHeader() can run more than once on a page, so this
        // file may be included more than once; only the first copy acts, or
        // the saved native function would be this override itself.
        if (window.logicoyErxEntryInstalled) {
            return;
        }
        if (typeof window.editScripts !== 'function' || typeof window.dlgopen !== 'function') {
            return;
        }
        window.logicoyErxEntryInstalled = true;
        window.logicoyNativeEditScripts = window.editScripts;
        window.editScripts = openLogicoy;
    });
})();
