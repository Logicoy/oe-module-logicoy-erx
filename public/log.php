<?php

/**
 * Prescription log for support and troubleshooting.
 *
 * Shows queue state and message identifiers only — no prescription content.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';

use LogiCoy\ERx\Bootstrap;
use LogiCoy\ERx\Queue\QueueRepository;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Core\Header;

if (!AclMain::aclCheckCore(Bootstrap::ACL_SECTION, Bootstrap::ACL_SUBSECTION)) {
    echo xlt('You are not authorized to view the prescription log.');
    exit;
}

$queue = new QueueRepository();
$patientFilter = isset($_GET['pid']) && $_GET['pid'] !== '' ? (int)$_GET['pid'] : null;

// Rows per page. The whole log is reachable through Previous / Next; a page
// is only what fits comfortably on screen.
$pageSize = 25;
$total = $queue->countRecent($patientFilter);
$pages = max(1, (int)ceil($total / $pageSize));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$rows = $queue->listRecent($pageSize, $patientFilter, ($page - 1) * $pageSize);
$first = $total === 0 ? 0 : ($page - 1) * $pageSize + 1;
$last = min($page * $pageSize, $total);

$pageUrl = static function (int $n) use ($patientFilter): string {
    $params = ['page' => $n];
    if ($patientFilter !== null) {
        $params['pid'] = $patientFilter;
    }
    return 'log.php?' . http_build_query($params);
};

$statusClass = [
    QueueRepository::STATUS_COMPLETE => 'success',
    QueueRepository::STATUS_PENDING => 'secondary',
    QueueRepository::STATUS_IN_FLIGHT => 'info',
    QueueRepository::STATUS_FAILED => 'warning',
    QueueRepository::STATUS_PARKED => 'danger',
];
?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(); ?>
    <title><?php echo xlt('LogiCoy eRx Log'); ?></title>
</head>
<body class="body_top">
<div class="container-fluid mt-3">
    <h2><?php echo xlt('LogiCoy eRx Log'); ?></h2>
    <p class="text-muted">
        <?php echo xlt('Queue state and message identifiers. Prescription content is not shown here.'); ?>
    </p>

    <?php if ($total === 0) : ?>
        <div class="alert alert-info"><?php echo xlt('No prescription traffic recorded yet.'); ?></div>
    <?php else : ?>
        <?php if ($patientFilter !== null) : ?>
            <p class="small text-muted">
                <?php echo xlt('Showing traffic for patient'); ?> <?php echo text((string)$patientFilter); ?>.
                <a href="log.php"><?php echo xlt('Show all'); ?></a>
            </p>
        <?php endif; ?>
        <table class="table table-sm table-striped">
            <thead>
            <tr>
                <th><?php echo xlt('Created'); ?></th>
                <th><?php echo xlt('Direction'); ?></th>
                <th><?php echo xlt('Type'); ?></th>
                <th><?php echo xlt('Message ID'); ?></th>
                <th><?php echo xlt('Patient'); ?></th>
                <th><?php echo xlt('Status'); ?></th>
                <th><?php echo xlt('Attempts'); ?></th>
                <th><?php echo xlt('Last Error'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row) : ?>
                <tr>
                    <td><?php echo text($row['created_at'] ?? ''); ?></td>
                    <td><?php echo text($row['direction']); ?></td>
                    <td><?php echo text($row['message_type']); ?></td>
                    <td><code><?php echo text($row['logicoy_message_id']); ?></code></td>
                    <td><?php echo text($row['patient_id'] ?? ''); ?></td>
                    <td>
                        <span class="badge badge-<?php echo attr($statusClass[$row['status']] ?? 'secondary'); ?>">
                            <?php echo text($row['status']); ?>
                        </span>
                    </td>
                    <td><?php echo text($row['attempts']); ?></td>
                    <td class="small text-muted"><?php echo text($row['last_error'] ?? ''); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($pages > 1) : ?>
            <div class="d-flex flex-wrap align-items-center mb-3" style="gap: .5rem;">
                <span class="small text-muted">
                    <?php echo text($first . '–' . $last . ' ' . xl('of') . ' ' . $total); ?>
                </span>
                <div class="btn-group btn-group-sm ml-auto">
                    <a class="btn btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>"
                       href="<?php echo attr($pageUrl($page - 1)); ?>"
                       <?php echo $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : ''; ?>>
                        &lsaquo; <?php echo xlt('Previous'); ?>
                    </a>
                    <a class="btn btn-outline-secondary <?php echo $page >= $pages ? 'disabled' : ''; ?>"
                       href="<?php echo attr($pageUrl($page + 1)); ?>"
                       <?php echo $page >= $pages ? 'aria-disabled="true" tabindex="-1"' : ''; ?>>
                        <?php echo xlt('Next'); ?> &rsaquo;
                    </a>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
