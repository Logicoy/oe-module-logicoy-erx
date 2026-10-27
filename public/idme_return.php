<?php

/**
 * Where ID.me sends the prescriber's browser back to.
 *
 * ID.me returns with a cross-site navigation, which does not carry OpenEMR's
 * SameSite=Strict session cookie. A module screen reached that way is refused
 * by OpenEMR core with an empty HTTP 400 before the module runs, so the return
 * lands here instead: a page that needs no session, takes no action, shows
 * nothing clinical, and links onward to the screen that asks LogiCoy for the
 * result. That click is same-site, so the session cookie travels with it;
 * if the session has gone, OpenEMR asks for a sign-in as it normally would.
 *
 * No ACL check: there is nothing here to protect. The result itself is only
 * ever fetched and shown on the signed-in screens.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Reached from ID.me, not from a signed-in session. Nothing here reads or
// writes anything; it only points the prescriber back into OpenEMR.
$ignoreAuth = true;

require_once __DIR__ . '/../../../../globals.php';

use LogiCoy\ERx\Identity\IdmeReturn;
use OpenEMR\Core\Header;

header('Cache-Control: no-store');

// Built from whole-number ids and a known target only; anything else ID.me
// or the backend put in the query is ignored.
$continue = IdmeReturn::continuePath($_GET);
?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(); ?>
    <title><?php echo xlt('Back from ID.me'); ?></title>
</head>
<body class="body_top">
<div class="container mt-4">
    <h2><?php echo xlt('Back from ID.me'); ?></h2>
    <p>
        <?php echo xlt('ID.me has finished and sent you back to OpenEMR.'); ?>
        <?php echo xlt('The result is confirmed with LogiCoy from your signed-in OpenEMR session.'); ?>
    </p>
    <?php if ($continue !== null) : ?>
        <p>
            <a class="btn btn-primary" href="<?php echo attr($continue); ?>">
                <?php echo xlt('Check the result'); ?>
            </a>
        </p>
        <p class="text-muted">
            <?php echo xlt('If OpenEMR asks you to sign in first, sign in,'); ?>
            <?php echo xlt('then press Check status on the LogiCoy eRx screen.'); ?>
        </p>
    <?php endif; ?>
    <p>
        <?php echo xlt('You can also go back to the OpenEMR tab you started from and press Check status there.'); ?>
        <?php echo xlt('This tab can be closed.'); ?>
    </p>
</div>
</body>
</html>
