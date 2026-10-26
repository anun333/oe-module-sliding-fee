<?php

/**
 * Sliding Fee Discount for one visit: the pay class in force on the date of service, the
 * most the patient pays, and the discount to post now. It posts nothing: staff post the
 * discount at checkout or in payment posting, under the Adjustment Reason shown.
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
use OpenHealthcare\SlidingFee\Domain\Assessment;
use OpenHealthcare\SlidingFee\Domain\VisitDiscount;
use OpenHealthcare\SlidingFee\Labels;
use OpenHealthcare\SlidingFee\Repository\AssessmentRepository;
use OpenHealthcare\SlidingFee\Repository\VisitRepository;
use OpenHealthcare\Common\OpenEmr;
use OpenHealthcare\SlidingFee\Guide;
use OpenHealthcare\SlidingFee\Page;

// The discount is posted at checkout: the same check as checkout.
if (!AclMain::aclCheckCore('acct', 'bill', '', 'write')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for acct/bill write: Sliding Fee Discount visit', xl('Sliding Fee Discount'));
}
$session = SessionWrapperFactory::getInstance()->getActiveSession();
$pid = (int)($_GET['pid'] ?? 0);
$encounter = (int)($_GET['encounter'] ?? 0);
$context = Context::load();
$repository = new VisitRepository();
$visit = null;
$patient = null;
$error = null;
if ($context->schedule === null) {
    $error = xl('The clinic has not set up its sliding fee schedule yet.');
} else {
    $visit = $repository->visit($pid, $encounter, $context->allReasons());
    $patient = QueryUtils::fetchRecords('SELECT `fname`, `lname`, `pubpid` FROM `patient_data` WHERE `pid` = ?', [$pid])[0] ?? null;
    if ($visit === null || $patient === null) {
        $error = xl('No such visit.');
    }
}
if ($error === null) {
    EventAuditLogger::getInstance()->newEvent('patient-record-select', (string)$session->get('authUser'), (string)$session->get('authProvider'), 1,
        "Sliding Fee Discount: visit $encounter viewed", $pid);

    // The visit's own schedule: a service schedule that names its category, else the main one.
    $schedule = $context->scheduleFor($visit['category']) ?? $context->schedule;
    // On Title X rules the visit's contraceptive products are family planning services too (42 CFR 59.2).
    $productFees = $schedule->discountedProductFees($repository->products($pid, $encounter));
    $fees = $visit['fees'] + $productFees;
    $inForce = Assessment::inForce((new AssessmentRepository())->forPatient($pid), $visit['date'], $schedule->reassessMonths);
    $placed = $inForce?->on($schedule, $context->guidelines);
    $result = VisitDiscount::compute($schedule, $placed, $fees, $visit['insurancePaid'], $visit['insuranceAdjusted'],
        $visit['patientPaid'], $visit['patientAdjusted'], $visit['slidingAdjusted'], $visit['insured'], $visit['settled']);
    $reason = $schedule->reasons[0] ?? '';
    $discountReasonAtCheckout = is_file(OpenEmr::projectDir() . '/src/Billing/CheckoutDiscountReason.php');
}
?>
<!DOCTYPE html>
<html lang="<?php echo attr(Page::lang()); ?>" dir="<?php echo attr(Page::dir()); ?>">
<head>
    <title><?php echo xlt('Sliding Fee Discount'); ?></title>
    <?php Header::setupHeader(); ?>
    <?php echo \OpenHealthcare\SlidingFee\Contrast::style(); ?>
</head>
<body class="body_top">
<div class="container-fluid py-3">
<?php if ($error !== null) { ?>
    <h1 class="h4"><?php echo xlt('Sliding fee discount for a visit'); ?></h1>
    <div class="alert alert-warning" role="alert" data-state="error"><?php echo text($error); ?></div>
</div>
</body>
</html>
<?php return;
} ?>
    <h1 class="h4"><?php echo xlt('Sliding fee discount for a visit'); ?>: <?php echo text(trim($patient['lname'] . ', ' . $patient['fname'], ', ')); ?>,
        <?php echo text(oeFormatShortDate($visit['date']->format('Y-m-d'))); ?></h1>
    <p class="small mb-2"><a href="<?php echo attr(Guide::url()); ?>" target="_blank" rel="noopener"><?php echo xlt('Help'); ?><span class="sr-only"> <?php echo xlt('(opens in a new window)'); ?></span></a></p>
    <p><a href="<?php echo attr(Context::url('patient.php', ['pid' => $pid])); ?>"><?php echo xlt('Assessments for this patient'); ?></a></p>

    <table class="table table-sm w-auto" id="figures">
        <?php if (count($context->inForce) > 1) { ?>
        <tr><th><?php echo xlt('Schedule'); ?></th><td id="schedule-name" data-service="<?php echo attr($schedule->service); ?>"><?php echo text($schedule->isMain() ? xl('Main schedule') : $schedule->name); ?></td></tr>
        <?php } ?>
        <tr><th><?php echo xlt('Pay class on the date of service'); ?></th><td data-class="<?php echo attr($result['class']); ?>"><?php echo text(Labels::payClass($result['class'], $schedule)); ?></td></tr>
        <tr><th><?php echo xlt('Charges at full fee'); ?></th><td class="text-right"><?php echo text(Labels::money($visit['fees'])); ?></td></tr>
        <?php if ($productFees > 0) { ?>
        <tr><th><?php echo xlt('Contraceptive products at full fee'); ?></th><td class="text-right" data-figure="products"><?php echo text(Labels::money($productFees)); ?></td></tr>
        <?php } ?>
        <tr><th><?php echo xlt('Paid and adjusted by insurance'); ?></th><td class="text-right"><?php echo text(Labels::money($visit['insurancePaid'] + $visit['insuranceAdjusted'])); ?></td></tr>
        <tr><th><?php echo xlt('Paid by the patient'); ?></th><td class="text-right"><?php echo text(Labels::money($visit['patientPaid'])); ?></td></tr>
        <tr><th><?php echo xlt('Sliding fee discounts already posted'); ?></th><td class="text-right"><?php echo text(Labels::money($visit['slidingAdjusted'])); ?></td></tr>
        <tr><th><?php echo xlt('The patient owes now'); ?></th><td class="text-right" data-figure="balance"><?php echo text(Labels::money($result['balance'])); ?></td></tr>
        <tr><th><?php echo xlt('The most the patient pays for this visit'); ?></th><td class="text-right" data-figure="charge"><?php echo text(Labels::money($result['charge'])); ?></td></tr>
        <tr class="table-primary"><th><?php echo xlt('Discount to post now'); ?></th><td class="text-right" data-figure="post"><?php echo text(Labels::money($result['post'])); ?></td></tr>
    </table>

    <?php if ($result['class'] === VisitDiscount::NONE) { ?>
        <div class="alert alert-warning"><?php echo xlt('No assessment was in force on the date of service, so no discount applies. Assess the patient first.'); ?></div>
    <?php } elseif ($result['pending']) { ?>
        <div class="alert alert-info" data-state="pending"><?php echo xlt('Insurance has not finished with this visit, so the discount is worked out once it has. Until then, the patient pays no more than the amount above, copay included.'); ?></div>
    <?php } elseif ($result['post'] > 0) { ?>
        <div class="alert alert-success" data-state="post">
            <?php echo text(sprintf(xl('Post a %1$s adjustment to the patient balance, with the Adjustment Reason %2$s.'), Labels::money($result['post']), $reason !== '' ? $reason : xl('your sliding fee discount reason'))); ?>
            <?php echo $discountReasonAtCheckout ? xlt('At checkout, enter it as the discount and choose that reason.') : xlt('This OpenEMR version records no reason for a checkout discount, so post it in payment posting, where the reason is kept.'); ?>
        </div>
    <?php } ?>
    <?php if ($result['overpaid'] > 0) { ?>
        <div class="alert alert-warning" data-state="overpaid"><?php echo text(sprintf(xl('The patient has paid %1$s more than the most they pay for this visit. Refund or credit it under your policy.'), Labels::money($result['overpaid']))); ?></div>
    <?php } ?>
    <?php if ($schedule->isTitleX()) { ?>
    <p class="small text-muted"><?php echo xlt('Insurers are billed the full fee; the discount only reduces what the patient pays. On Title X rules the contraceptive products sold at this visit are discounted too (42 CFR 59.2 and 59.5); other products sold at checkout are not.'); ?></p>
    <?php } else { ?>
    <p class="small text-muted"><?php echo xlt('Insurers are billed the full fee; the sliding fee discount only reduces what the patient pays (HRSA Chapters 9 and 16). Prescription drugs, eyeglasses and dentures are not covered by the sliding fee discount program.'); ?></p>
    <?php } ?>
</div>
</body>
</html>
