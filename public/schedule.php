<?php

/**
 * Sliding Fee Discount: the schedule, checked against HRSA's Compliance Manual Chapter 9
 * as it's saved. A schedule with errors isn't saved; warnings are shown with it.
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
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenHealthcare\SlidingFee\Context;
use OpenHealthcare\SlidingFee\Domain\PovertyGuidelines;
use OpenHealthcare\SlidingFee\Domain\Schedule;
use OpenHealthcare\SlidingFee\Domain\ScheduleCheck;

if (!AclMain::aclCheckCore('admin', 'super')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for admin/super: Sliding Fee Discount schedule', xl('Sliding Fee Discount'));
}
$session = SessionWrapperFactory::getInstance()->getActiveSession();
$context = Context::load();
$today = new DateTimeImmutable('today');
$schedule = $context->schedule;
$saved = false;
$findings = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '', session: $session)) {
        CsrfUtils::csrfNotVerified();
    }
    $classes = [];
    foreach (is_array($_POST['classes'] ?? null) ? $_POST['classes'] : [] as $row) {
        if (is_array($row)) {
            $classes[] = $row;
        }
    }
    $schedule = Schedule::fromArray([
        'region' => $_POST['region'] ?? '',
        'year' => $_POST['year'] ?? '',
        'atOrBelow100' => $_POST['atOrBelow100'] ?? '',
        'nominal' => $_POST['nominal'] ?? '',
        'classes' => $classes,
        'reassessMonths' => $_POST['reassessMonths'] ?? '',
        'reasons' => is_array($_POST['reasons'] ?? null) ? $_POST['reasons'] : [],
        'otherFundingAbove200' => !empty($_POST['otherFundingAbove200']),
        'approvedOn' => $_POST['approvedOn'] ?? '',
    ]);
    $findings = ScheduleCheck::run($schedule, $context->guidelines, $context->schedules->fees(), $today);
    if (!in_array(ScheduleCheck::ERROR, array_column($findings, 'level'), true)) {
        $context->schedules->save($schedule, (int)$session->get('authUserID'));
        $saved = true;
    }
} elseif ($schedule !== null) {
    $findings = ScheduleCheck::run($schedule, $context->guidelines, $context->schedules->fees(), $today);
}

$form = $schedule ?? new Schedule('contiguous', (int)($context->guidelines->latestYear('contiguous') ?? (int)$today->format('Y')), Schedule::FULL, 0, [
    ['label' => 'A', 'upTo' => 125, 'method' => Schedule::PERCENT, 'value' => 0],
    ['label' => 'B', 'upTo' => 150, 'method' => Schedule::PERCENT, 'value' => 0],
    ['label' => 'C', 'upTo' => 175, 'method' => Schedule::PERCENT, 'value' => 0],
    ['label' => 'D', 'upTo' => 200, 'method' => Schedule::PERCENT, 'value' => 0],
], 12, ['Sliding fee discount']);
$rows = $form->classes;
while (count($rows) < 6) {
    $rows[] = ['label' => '', 'upTo' => '', 'method' => Schedule::PERCENT, 'value' => ''];
}
$regions = ['contiguous' => xl('48 contiguous states and DC'), 'alaska' => xl('Alaska'), 'hawaii' => xl('Hawaii')];
$years = [];
foreach (array_keys($regions) as $r) {
    for ($y = (int)$today->format('Y') + 1; $y >= 2025; $y--) {
        if ($context->guidelines->has($y, $r)) {
            $years[$y] = true;
        }
    }
}
krsort($years);
$reasons = $context->schedules->adjustmentReasons();
$number = static fn(mixed $v): string => is_float($v) || is_int($v) ? rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.') : (string)$v;
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Sliding fee schedule'); ?></title>
    <?php Header::setupHeader(); ?>
</head>
<body class="body_top">
<div class="container-fluid py-3">
    <h1 class="h4"><?php echo xlt('Sliding fee schedule'); ?></h1>
    <p class="small text-muted"><?php echo xlt('Checked against the HRSA Health Center Program Compliance Manual, Chapter 9, when it is saved. A schedule with errors is not saved. Insurers are still billed the full fee; the discount is an adjustment to what the patient owes.'); ?>
        <a href="<?php echo attr(Context::url('index.php')); ?>"><?php echo xlt('Back'); ?></a></p>

    <?php if ($saved) { ?>
        <div class="alert alert-success"><?php echo xlt('Saved. This schedule is now in force.'); ?></div>
    <?php } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') { ?>
        <div class="alert alert-danger"><?php echo xlt('Not saved: the schedule has errors.'); ?></div>
    <?php } elseif ($context->savedAt !== null) { ?>
        <p class="small"><?php echo xlt('In force since'); ?> <?php echo text(oeFormatShortDate(substr($context->savedAt, 0, 10))); ?></p>
    <?php } ?>
    <?php foreach ($findings as $f) { ?>
        <div class="alert <?php echo $f['level'] === ScheduleCheck::ERROR ? 'alert-danger' : 'alert-warning'; ?> py-2 small" data-level="<?php echo attr($f['level']); ?>"><?php echo text($f['message']); ?></div>
    <?php } ?>

    <form method="post">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken(session: $session)); ?>">
        <div class="form-row">
            <div class="form-group col-md-4">
                <label for="region"><?php echo xlt('Poverty guidelines for'); ?></label>
                <select class="form-control form-control-sm" id="region" name="region">
                    <?php foreach ($regions as $id => $label) { ?>
                        <option value="<?php echo attr($id); ?>" <?php echo $form->region === $id ? 'selected' : ''; ?>><?php echo text($label); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="form-group col-md-2">
                <label for="year"><?php echo xlt('Year'); ?></label>
                <select class="form-control form-control-sm" id="year" name="year">
                    <?php foreach (array_keys($years) as $y) { ?>
                        <option value="<?php echo attr((string)$y); ?>" <?php echo $form->year === $y ? 'selected' : ''; ?>><?php echo text((string)$y); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="form-group col-md-3">
                <label for="approvedOn"><?php echo xlt('Board approved on'); ?></label>
                <input type="date" class="form-control form-control-sm" id="approvedOn" name="approvedOn" value="<?php echo attr($form->approvedOn ?? ''); ?>">
            </div>
            <div class="form-group col-md-3">
                <label for="reassessMonths"><?php echo xlt('Re-assess every (months)'); ?></label>
                <input type="number" min="1" max="60" class="form-control form-control-sm" id="reassessMonths" name="reassessMonths" value="<?php echo attr((string)$form->reassessMonths); ?>">
            </div>
        </div>

        <fieldset class="form-group">
            <legend class="h6"><?php echo xlt('At or below 100% of the guidelines'); ?></legend>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="atOrBelow100" id="full" value="full" <?php echo $form->atOrBelow100 === Schedule::FULL ? 'checked' : ''; ?>>
                <label class="form-check-label" for="full"><?php echo xlt('Full discount'); ?></label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="atOrBelow100" id="nominal" value="nominal" <?php echo $form->atOrBelow100 === Schedule::NOMINAL ? 'checked' : ''; ?>>
                <label class="form-check-label" for="nominal"><?php echo xlt('Nominal charge per visit'); ?></label>
            </div>
            <input type="number" step="0.01" min="0" class="form-control form-control-sm d-inline-block w-auto" name="nominal" aria-label="<?php echo xla('Nominal charge'); ?>" value="<?php echo attr($number($form->nominal)); ?>">
        </fieldset>

        <h2 class="h6"><?php echo xlt('Pay classes above 100%'); ?></h2>
        <p class="small text-muted"><?php echo xlt('Each class runs up to its percentage of the guidelines. HRSA requires at least three classes between 101% and 200%, and no discount above 200% unless other funding pays for it. Leave the name empty to remove a row.'); ?></p>
        <table class="table table-sm w-auto">
            <thead><tr><th><?php echo xlt('Name'); ?></th><th><?php echo xlt('Up to (% of guidelines)'); ?></th><th><?php echo xlt('Discount'); ?></th><th><?php echo xlt('Amount'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $c) { ?>
                <tr>
                    <td><input class="form-control form-control-sm" name="classes[<?php echo attr((string)$i); ?>][label]" value="<?php echo attr((string)$c['label']); ?>" maxlength="32" aria-label="<?php echo xla('Name'); ?>"></td>
                    <td><input type="number" step="0.01" class="form-control form-control-sm" name="classes[<?php echo attr((string)$i); ?>][upTo]" value="<?php echo attr($number($c['upTo'])); ?>" aria-label="<?php echo xla('Up to'); ?>"></td>
                    <td><select class="form-control form-control-sm" name="classes[<?php echo attr((string)$i); ?>][method]" aria-label="<?php echo xla('Discount'); ?>">
                        <option value="percent" <?php echo $c['method'] === Schedule::PERCENT ? 'selected' : ''; ?>><?php echo xlt('Percent off the fee'); ?></option>
                        <option value="flat" <?php echo $c['method'] === Schedule::FLAT ? 'selected' : ''; ?>><?php echo xlt('Flat charge per visit'); ?></option>
                    </select></td>
                    <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="classes[<?php echo attr((string)$i); ?>][value]" value="<?php echo attr($number($c['value'])); ?>" aria-label="<?php echo xla('Amount'); ?>"></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="otherFundingAbove200" name="otherFundingAbove200" value="1" <?php echo $form->otherFundingAbove200 ? 'checked' : ''; ?>>
            <label class="form-check-label" for="otherFundingAbove200"><?php echo xlt('Other funding pays for discounts above 200% (for example a Ryan White award)'); ?></label>
        </div>

        <h2 class="h6"><?php echo xlt('Adjustment Reasons posted as sliding fee discounts'); ?></h2>
        <p class="small text-muted"><?php echo xlt('The evaluation report counts adjustments posted under these. Only reasons that change the balance are listed.'); ?></p>
        <?php foreach ($reasons as $r) { ?>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="reasons[]" id="reason-<?php echo attr(md5($r['id'])); ?>" value="<?php echo attr($r['id']); ?>" <?php echo in_array($r['id'], $form->reasons, true) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="reason-<?php echo attr(md5($r['id'])); ?>"><?php echo text($r['title']); ?></label>
            </div>
        <?php } ?>
        <button type="submit" class="btn btn-primary btn-sm mt-3"><?php echo xlt('Check and save'); ?></button>
    </form>
</div>
</body>
</html>
