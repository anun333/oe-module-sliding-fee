<?php

/**
 * Sliding Fee Discount: the schedule in force and its compliance check, with the pages
 * this user may open.
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
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenHealthcare\SlidingFee\Context;
use OpenHealthcare\SlidingFee\Domain\Schedule;
use OpenHealthcare\SlidingFee\Domain\ScheduleCheck;
use OpenHealthcare\SlidingFee\Labels;

if (!AclMain::aclCheckCore('acct', 'bill')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for acct/bill: Sliding Fee Discount', xl('Sliding Fee Discount'));
}
$session = SessionWrapperFactory::getInstance()->getActiveSession();
$context = Context::load();
$schedule = $context->schedule;
$findings = $schedule === null ? [] : ScheduleCheck::run($schedule, $context->guidelines, $context->schedules->fees(), new DateTimeImmutable('today'));
$pid = (int)($session->get('pid') ?? 0);
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Sliding Fee Discount'); ?></title>
    <?php Header::setupHeader(); ?>
</head>
<body class="body_top">
<div class="container-fluid py-3">
    <h1 class="h4"><?php echo xlt('Sliding Fee Discount'); ?></h1>
    <p class="small text-muted"><?php echo xlt('A sliding fee discount program as HRSA requires of health centers: the schedule checked against Compliance Manual Chapter 9, income assessments, the discount each visit is owed, and the figures for the program evaluation. It posts nothing to accounts: staff post each discount with its Adjustment Reason.'); ?></p>

    <?php if ($schedule === null) { ?>
        <div class="alert alert-warning"><?php echo xlt('No schedule yet. An administrator sets it up from the board-approved schedule.'); ?></div>
    <?php } else { ?>
        <h2 class="h5"><?php echo xlt('Schedule in force'); ?></h2>
        <ul>
            <li><?php echo text(Labels::payClass(Schedule::AT_OR_BELOW_100, $schedule)); ?></li>
            <?php foreach ($schedule->classes as $c) { ?><li><?php echo text(Labels::payClass($c['label'], $schedule)); ?></li><?php } ?>
            <li><?php echo text(Labels::payClass(Schedule::NO_DISCOUNT, $schedule)); ?></li>
        </ul>
        <p class="small"><?php echo text(sprintf(xl('Guidelines %1$s (%2$s); re-assess every %3$s months; in force since %4$s.'), $schedule->year, $schedule->region, $schedule->reassessMonths, oeFormatShortDate(substr((string)$context->savedAt, 0, 10)))); ?></p>
        <?php foreach ($findings as $f) { ?>
            <div class="alert <?php echo $f['level'] === ScheduleCheck::ERROR ? 'alert-danger' : 'alert-warning'; ?> py-2 small"><?php echo text($f['message']); ?></div>
        <?php } ?>
        <?php if ($findings === []) { ?><p class="text-success small"><?php echo xlt('The schedule meets every requirement the check covers.'); ?></p><?php } ?>
    <?php } ?>

    <div class="mt-3">
        <?php if (AclMain::aclCheckCore('admin', 'super')) { ?>
            <a class="btn btn-sm btn-outline-primary mr-2" href="<?php echo attr(Context::url('schedule.php')); ?>"><?php echo xlt('Schedule'); ?></a>
        <?php } ?>
        <?php if (AclMain::aclCheckCore('acct', 'rep_a')) { ?>
            <a class="btn btn-sm btn-outline-primary mr-2" href="<?php echo attr(Context::url('evaluation.php')); ?>"><?php echo xlt('Evaluation'); ?></a>
        <?php } ?>
        <?php if ($pid > 0 && AclMain::aclCheckCore('patients', 'demo', '', 'write')) { ?>
            <a class="btn btn-sm btn-outline-primary" href="<?php echo attr(Context::url('patient.php', ['pid' => $pid])); ?>"><?php echo xlt('Current patient'); ?></a>
        <?php } ?>
    </div>
</div>
</body>
</html>
