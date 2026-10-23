<?php

/**
 * Sliding Fee Discount: patients seen in the last 12 months whose assessment runs out within
 * 30 days, has run out, or who were never assessed, so staff can re-assess them at their next
 * visit. The center decides how often to re-assess (Compliance Manual Chapter 9, element g);
 * the main schedule holds that period.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AccessDeniedHelper;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenHealthcare\SlidingFee\Context;
use OpenHealthcare\SlidingFee\Domain\Reassessment;
use OpenHealthcare\SlidingFee\Repository\AssessmentRepository;
use OpenHealthcare\SlidingFee\Repository\VisitRepository;

// A list of patients and when they were assessed: the same check as reading Demographics.
if (!AclMain::aclCheckCore('patients', 'demo')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for patients/demo: Sliding Fee Discount re-assessments due', xl('Sliding Fee Discount'));
}
const WINDOW_DAYS = 30;
$session = SessionWrapperFactory::getInstance()->getActiveSession();
$context = Context::load();
$schedule = $context->schedule;
$today = new DateTimeImmutable('today');
$due = [];
$patients = [];
if ($schedule !== null) {
    $lastVisits = (new VisitRepository())->lastVisitsSince($today->modify('-12 months'));
    $due = Reassessment::due($lastVisits, (new AssessmentRepository())->forPatients(array_keys($lastVisits)), $today, $schedule->reassessMonths, WINDOW_DAYS);
    $pids = array_column($due, 'pid');
    if ($pids !== []) {
        $rows = QueryUtils::fetchRecords(
            'SELECT `pid`, `fname`, `lname`, `pubpid` FROM `patient_data` WHERE `pid` IN (' . implode(', ', array_fill(0, count($pids), '?')) . ')',
            $pids,
        );
        foreach ($rows as $r) {
            $patients[(int)$r['pid']] = $r;
        }
        $user = $session->get('authUser');
        $group = $session->get('authProvider');
        EventAuditLogger::getInstance()->newEvent('patient-record-select', is_string($user) ? $user : '', is_string($group) ? $group : '', 1,
            'Sliding Fee Discount: re-assessments due viewed, ' . count($pids) . ' patients, pids: ' . implode(',', $pids));
    }
}
$status = [
    Reassessment::NEVER => xl('Never assessed'),
    Reassessment::EXPIRED => xl('Assessment ran out'),
    Reassessment::SOON => xl('Runs out soon'),
];
$canAssess = AclMain::aclCheckCore('patients', 'demo', '', 'write');
?>
<!DOCTYPE html>
<?php // The module's own text is written in English. ?>
<html lang="en">
<head>
    <title><?php echo xlt('Sliding fee re-assessments due'); ?></title>
    <?php Header::setupHeader(); ?>
</head>
<body class="body_top">
<div class="container-fluid py-3">
    <h1 class="h4"><?php echo xlt('Sliding fee re-assessments due'); ?></h1>
    <p class="small text-muted"><?php echo $schedule === null ? '' : text(sprintf(xl('Patients seen in the last 12 months whose assessment runs out within %1$s days, has run out, or who were never assessed. The schedule says to re-assess every %2$s months.'), WINDOW_DAYS, $schedule->reassessMonths)); ?>
        <a href="<?php echo attr(Context::url('index.php')); ?>"><?php echo xlt('Back'); ?></a></p>
    <?php if ($schedule === null) { ?>
        <div class="alert alert-warning"><?php echo xlt('The clinic has not set up its sliding fee schedule yet.'); ?></div>
    <?php } elseif ($due === []) { ?>
        <p id="none"><?php echo xlt('No one is due.'); ?></p>
    <?php } else { ?>
        <table class="table table-sm w-auto" id="due">
            <thead><tr><th><?php echo xlt('Patient'); ?></th><th><?php echo xlt('ID'); ?></th><th><?php echo xlt('Status'); ?></th>
                <th><?php echo xlt('Last assessed'); ?></th><th><?php echo xlt('Re-assess by'); ?></th><th><?php echo xlt('Last visit'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($due as $row) {
                $p = $patients[$row['pid']] ?? ['fname' => '', 'lname' => '', 'pubpid' => '']; ?>
                <tr data-pid="<?php echo attr((string)$row['pid']); ?>" data-status="<?php echo attr($row['status']); ?>">
                    <td><?php if ($canAssess) { ?><a href="<?php echo attr(Context::url('patient.php', ['pid' => $row['pid']])); ?>"><?php } ?><?php echo text(trim($p['lname'] . ', ' . $p['fname'], ', ')); ?><?php if ($canAssess) { ?></a><?php } ?></td>
                    <td><?php echo text((string)$p['pubpid']); ?></td>
                    <td><?php echo text($status[$row['status']]); ?></td>
                    <td><?php echo $row['assessedOn'] === null ? '' : text(oeFormatShortDate($row['assessedOn']->format('Y-m-d'))); ?></td>
                    <td><?php echo $row['dueOn'] === null ? '' : text(oeFormatShortDate($row['dueOn']->format('Y-m-d'))); ?></td>
                    <td><?php echo text(oeFormatShortDate($row['lastVisit']->format('Y-m-d'))); ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    <?php } ?>
</div>
</body>
</html>
