<?php

/**
 * The module's one entry under Modules: every LogiCoy eRx screen as a tab.
 *
 * Each tab loads the existing screen in a frame, the way Manage Modules hosts
 * the setup screen, so the screens keep their own form handling, scripts and
 * ID.me return trips. The Settings tab is only offered to administrators; the
 * setup screen checks the same ACL itself, so a typed URL gets no further.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';

use LogiCoy\ERx\Bootstrap;
use LogiCoy\ERx\Config\GlobalConfig;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Core\Header;

$canPrescribe = AclMain::aclCheckCore(Bootstrap::ACL_SECTION, Bootstrap::ACL_SUBSECTION);
$isAdmin = AclMain::aclCheckCore('admin', 'super');

if (!$canPrescribe && !$isAdmin) {
    echo xlt('You are not authorized to use LogiCoy eRx.');
    exit;
}

if (!(new GlobalConfig($GLOBALS))->isModuleEnabled()) {
    echo xlt('The LogiCoy eRx module is not enabled.');
    exit;
}

// id => [label, page], in the order shown. The prescribing screen needs a
// patient, so it is reached from the patient's summary rather than from here.
$tabs = [];
if ($canPrescribe) {
    $tabs['inbox'] = [xl('Inbox'), 'inbox.php'];
    $tabs['idme'] = [xl('ID.me Verification'), 'idme.php'];
    $tabs['log'] = [xl('Log'), 'log.php'];
}
if ($isAdmin) {
    $tabs['settings'] = [xl('Settings'), '../moduleConfig.php'];
}

$requested = (string)($_GET['tab'] ?? '');
$activeTab = isset($tabs[$requested]) ? $requested : array_key_first($tabs);
?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(); ?>
    <title><?php echo xlt('LogiCoy eRx'); ?></title>
    <style>
        html, body { height: 100%; }
        body.body_top { display: flex; flex-direction: column; overflow: hidden; }
        .erx-hub-tabs { flex: 0 0 auto; padding: .5rem 1rem 0; background: var(--white, #fff); }
        .erx-hub-tabs .nav-link { padding: .4rem .9rem; }
        .erx-hub-frame { flex: 1 1 auto; width: 100%; border: 0; }
    </style>
</head>
<body class="body_top">
<ul class="nav nav-tabs erx-hub-tabs" role="tablist">
    <?php foreach ($tabs as $id => [$label, $page]) : ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $id === $activeTab ? 'active' : ''; ?>"
               href="?tab=<?php echo attr($id); ?>" role="tab"
               data-tab="<?php echo attr($id); ?>" data-page="<?php echo attr($page); ?>">
                <?php echo text($label); ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>
<iframe class="erx-hub-frame" id="erx_frame" title="<?php echo xla('LogiCoy eRx'); ?>"
        src="<?php echo attr($tabs[$activeTab][1]); ?>"></iframe>
<script>
    // Switch the frame in place; the URL keeps the tab so a reload or a
    // bookmark comes back to it.
    (function () {
        var frame = document.getElementById('erx_frame');
        var links = document.querySelectorAll('.erx-hub-tabs .nav-link');
        Array.prototype.forEach.call(links, function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                Array.prototype.forEach.call(links, function (l) { l.classList.remove('active'); });
                link.classList.add('active');
                frame.src = link.getAttribute('data-page');
                history.replaceState(null, '', '?tab=' + encodeURIComponent(link.getAttribute('data-tab')));
            });
        });
    })();
</script>
</body>
</html>
