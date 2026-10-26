<?php

/**
 * Sliding Fee Discount for one patient: record an income assessment (which also updates
 * Demographics: family size, monthly income and Financial Review Date), see every
 * assessment, and open the discount for a recent visit.
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
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenHealthcare\SlidingFee\Context;
use OpenHealthcare\SlidingFee\Domain\Assessment;
use OpenHealthcare\SlidingFee\Domain\Schedule;
use OpenHealthcare\SlidingFee\Domain\VisitDiscount;
use OpenHealthcare\SlidingFee\Labels;
use OpenHealthcare\SlidingFee\Repository\AssessmentRepository;
use OpenHealthcare\SlidingFee\Repository\VisitRepository;
use OpenHealthcare\Common\OpenEmr;

// Recording an assessment writes Demographics: the same check as the Demographics editor.
if (!AclMain::aclCheckCore('patients', 'demo', '', 'write')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for patients/demo write: Sliding Fee Discount', xl('Sliding Fee Discount'));
}
$session = SessionWrapperFactory::getInstance()->getActiveSession();
$pid = (int)($_GET['pid'] ?? $session->get('pid') ?? 0);
$patient = QueryUtils::fetchRecords('SELECT `pid`, `fname`, `lname`, `DOB`, `pubpid`, `family_size`, `monthly_income` FROM `patient_data` WHERE `pid` = ?', [$pid])[0] ?? null;
if ($patient === null) {
    die(xlt('No such patient.'));
}
$context = Context::load();
$schedule = $context->schedule;
$repo = new AssessmentRepository();
$today = new DateTimeImmutable('today');
$errors = [];
$saved = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $schedule !== null) {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '', session: $session)) {
        CsrfUtils::csrfNotVerified();
    }
    $method = in_array($_POST['method'] ?? '', Assessment::METHODS, true) ? (string)$_POST['method'] : '';
    $on = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_POST['assessed_on'] ?? ''));
    $income = is_numeric($_POST['annual_income'] ?? null) ? (float)$_POST['annual_income'] : null;
    $size = is_numeric($_POST['family_size'] ?? null) ? (int)$_POST['family_size'] : null;
    $basis = in_array($_POST['basis'] ?? '', Assessment::BASES, true) ? (string)$_POST['basis'] : Assessment::FAMILY;
    $note = trim((string)($_POST['note'] ?? ''));
    if ($basis === Assessment::DIRECTOR && $note === '') {
        $errors[] = xl('Record the reason of the project director in the note: Title X counts a patient as low-income when they are unable, for good reasons, to pay (42 CFR 59.2).');
    }
    if ($method === '') {
        $errors[] = xl('Choose how the income was documented, or that the patient declined.');
    }
    if ($on === false || $on > $today) {
        $errors[] = xl('The assessment date must be today or earlier.');
    }
    if ($method !== Assessment::DECLINED && ($income === null || $income < 0 || $size === null || $size < 1)) {
        $errors[] = xl('Enter the annual income and a family size of at least 1.');
    }
    if ($errors === [] && $on !== false) {
        $percent = $method === Assessment::DECLINED || $income === null || $size === null ? null : $context->guidelines->percent($income, $size, $schedule->year, $schedule->region);
        $class = $percent === null ? Schedule::NO_DISCOUNT : $schedule->classFor($percent);
        $assessment = new Assessment(0, $pid, $on, $method, $method === Assessment::DECLINED ? null : $income, $method === Assessment::DECLINED ? null : $size, $percent, $class, $basis);
        $id = $repo->add($assessment, $context->scheduleId, $note, (int)$session->get('authUserID'));
        EventAuditLogger::getInstance()->newEvent('patient-record-update', (string)$session->get('authUser'), (string)$session->get('authProvider'), 1,
            "Sliding Fee Discount: assessment $id recorded ($method, $basis)", $pid);
        $saved = true;
    }
}
EventAuditLogger::getInstance()->newEvent('patient-record-select', (string)$session->get('authUser'), (string)$session->get('authProvider'), 1,
    'Sliding Fee Discount: assessments viewed', $pid);

$assessments = $repo->forPatient($pid);
$details = $repo->details($pid);
$inForce = $schedule === null ? null : Assessment::inForce($assessments, $today, $schedule->reassessMonths);
$canBill = AclMain::aclCheckCore('acct', 'bill', '', 'write');
$visits = $canBill ? (new VisitRepository())->recent($pid) : [];
$monthly = is_numeric($patient['monthly_income'] ?? null) ? (float)$patient['monthly_income'] : null;
?>
<!DOCTYPE html>
<?php // The module's own text is written in English. ?>
<html lang="en">
<head>
    <title><?php echo xlt('Sliding Fee Discount'); ?></title>
    <?php Header::setupHeader(); ?>
</head>
<body class="body_top">
<div class="container-fluid py-3">
    <h1 class="h4"><?php echo xlt('Sliding Fee Discount'); ?>: <?php echo text(trim($patient['lname'] . ', ' . $patient['fname'], ', ')); ?>
        <small class="text-muted"><?php echo xlt('ID'); ?> <?php echo text((string)$patient['pubpid']); ?></small></h1>

    <?php if ($schedule === null) { ?>
        <div class="alert alert-warning"><?php echo xlt('The clinic has not set up its sliding fee schedule yet, so no assessment can be placed in a pay class.'); ?></div>
    <?php } else { ?>
        <?php if ($saved) { ?><div class="alert alert-success"><?php echo xlt('Assessment recorded. Demographics now has the same family size, monthly income and Financial Review Date.'); ?></div><?php } ?>
        <?php foreach ($errors as $e) { ?><div class="alert alert-danger py-2"><?php echo text($e); ?></div><?php } ?>
        <h2 class="h5"><?php echo xlt('In force today'); ?></h2>
        <?php if ($inForce === null) { ?>
            <p><?php echo xlt('No assessment in force: no discount applies until the patient is assessed.'); ?></p>
        <?php } else {
            $placed = $inForce->on($schedule, $context->guidelines); ?>
            <p data-class="<?php echo attr($inForce->method === Assessment::DECLINED ? VisitDiscount::DECLINED : $placed->classLabel); ?>">
                <strong><?php echo text(Labels::payClass($inForce->method === Assessment::DECLINED ? VisitDiscount::DECLINED : $placed->classLabel, $schedule)); ?></strong>
                <?php if ($placed->percent !== null) { ?> · <?php echo text(Labels::percent($placed->percent)); ?> <?php echo xlt('of the guidelines'); ?><?php } ?>
                · <?php echo xlt('re-assess by'); ?> <?php echo text(oeFormatShortDate($inForce->expiresOn($schedule->reassessMonths)->format('Y-m-d'))); ?>
            </p>
        <?php } ?>

        <h2 class="h5 mt-4"><?php echo xlt('Record an assessment'); ?></h2>
        <p class="small text-muted"><?php echo xlt('Income and family size only, whatever the insurance (HRSA Chapter 9). The clinic policy decides what counts as income and family, and what proof to ask for.'); ?>
            <?php echo xlt('Guidelines'); ?>: <?php echo text((string)$schedule->year); ?>, <?php echo text($context->guidelines->source($schedule->year, $schedule->region) ?? ''); ?>.</p>
        <?php if (QueryUtils::fetchRecords("SELECT 1 FROM `modules` WHERE `mod_directory` = 'oe-module-clinic-forms' AND `mod_active` = 1 LIMIT 1") !== []) { ?>
            <?php // Clinic Forms' application, ticked and filled for the patient whose chart is open; staff print it there. ?>
            <p class="small"><a href="<?php echo attr(OpenEmr::webRoot() . '/interface/modules/custom_modules/oe-module-clinic-forms/public/index.php?select=doc:sliding-fee-application,doc:sliding-fee-notice&fill=1'); ?>"><?php echo xlt('Print the application and the notice (Clinic Forms)'); ?></a></p>
        <?php } ?>
        <form method="post" class="form-row align-items-end">
            <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken(session: $session)); ?>">
            <div class="form-group col-md-2">
                <label for="assessed_on"><?php echo xlt('Date'); ?></label>
                <input type="date" class="form-control form-control-sm" id="assessed_on" name="assessed_on" value="<?php echo attr($today->format('Y-m-d')); ?>">
            </div>
            <div class="form-group col-md-2">
                <label for="annual_income"><?php echo xlt('Annual income'); ?></label>
                <input type="number" step="0.01" min="0" class="form-control form-control-sm" id="annual_income" name="annual_income" value="<?php echo attr($monthly === null ? '' : number_format($monthly * 12, 2, '.', '')); ?>">
            </div>
            <div class="form-group col-md-1">
                <label for="family_size"><?php echo xlt('Family size'); ?></label>
                <input type="number" min="1" class="form-control form-control-sm" id="family_size" name="family_size" value="<?php echo attr((string)($patient['family_size'] ?? '')); ?>">
            </div>
            <div class="form-group col-md-3">
                <label for="method"><?php echo xlt('Documented by'); ?></label>
                <select class="form-control form-control-sm" id="method" name="method">
                    <option value=""></option>
                    <?php foreach (Assessment::METHODS as $m) { ?><option value="<?php echo attr($m); ?>"><?php echo text(Labels::method($m)); ?></option><?php } ?>
                </select>
            </div>
            <?php if (array_filter(array_column($context->inForce, 'schedule'), static fn(Schedule $s): bool => $s->isTitleX()) !== []) { ?>
            <div class="form-group col-md-3">
                <label for="basis"><?php echo xlt('Based on'); ?></label>
                <select class="form-control form-control-sm" id="basis" name="basis" aria-describedby="basis-help">
                    <?php foreach (Assessment::BASES as $b) { ?><option value="<?php echo attr($b); ?>"><?php echo text(Labels::basis($b)); ?></option><?php } ?>
                </select>
                <small id="basis-help" class="form-text text-muted"><?php echo xlt('Title X only (42 CFR 59.2). For a minor assessed on own resources, enter the income of the minor and a family size of 1; the family figures in Demographics are left as they are.'); ?></small>
            </div>
            <?php } ?>
            <div class="form-group col-md-3">
                <label for="note"><?php echo xlt('Note'); ?></label>
                <input class="form-control form-control-sm" id="note" name="note" maxlength="500">
            </div>
            <div class="form-group col-md-1"><button type="submit" class="btn btn-primary btn-sm"><?php echo xlt('Record'); ?></button></div>
        </form>
    <?php } ?>

    <h2 class="h5 mt-4"><?php echo xlt('Assessments'); ?></h2>
    <table class="table table-sm">
        <thead><tr><th><?php echo xlt('Date'); ?></th><th><?php echo xlt('Documented by'); ?></th><th class="text-right"><?php echo xlt('Annual income'); ?></th>
            <th class="text-right"><?php echo xlt('Family size'); ?></th><th class="text-right"><?php echo xlt('Of the guidelines'); ?></th><th><?php echo xlt('Pay class when assessed'); ?></th><th><?php echo xlt('Recorded by'); ?></th><th><?php echo xlt('Note'); ?></th></tr></thead>
        <tbody>
        <?php foreach ($assessments as $a) {
            $d = $details[$a->id] ?? ['note' => '', 'user' => '', 'created' => '']; ?>
            <tr data-assessment="<?php echo attr((string)$a->id); ?>">
                <td><?php echo text(oeFormatShortDate($a->assessedOn->format('Y-m-d'))); ?></td>
                <td><?php echo text(Labels::method($a->method)); ?></td>
                <td class="text-right"><?php echo $a->annualIncome === null ? '' : text(Labels::money($a->annualIncome)); ?></td>
                <td class="text-right"><?php echo text((string)($a->familySize ?? '')); ?></td>
                <td class="text-right"><?php echo text(Labels::percent($a->percent)); ?></td>
                <td><?php echo text($a->method === Assessment::DECLINED ? Labels::payClass(VisitDiscount::DECLINED) : Labels::payClass($a->classLabel)); ?></td>
                <td><?php echo text($d['user']); ?></td>
                <td><?php echo text($d['note']); ?></td>
            </tr>
        <?php } ?>
        <?php if ($assessments === []) { ?><tr><td colspan="8" class="text-muted"><?php echo xlt('None yet.'); ?></td></tr><?php } ?>
        </tbody>
    </table>

    <?php if ($canBill && $schedule !== null) { ?>
        <h2 class="h5 mt-4"><?php echo xlt('Visits'); ?></h2>
        <ul class="list-unstyled">
            <?php foreach ($visits as $v) { ?>
                <li><a href="<?php echo attr(Context::url('visit.php', ['pid' => $pid, 'encounter' => $v['encounter']])); ?>"><?php echo text(oeFormatShortDate($v['date'])); ?></a>
                    <span class="text-muted"><?php echo text($v['reason']); ?></span></li>
            <?php } ?>
            <?php if ($visits === []) { ?><li class="text-muted"><?php echo xlt('No visits.'); ?></li><?php } ?>
        </ul>
    <?php } ?>
</div>
</body>
</html>
