<?php

/**
 * Sliding Fee Discount evaluation: utilization by pay class for a period, as HRSA's
 * Compliance Manual Chapter 9, element l, asks the health center to collect at least once
 * every three years. Counts only. ?format=csv downloads the same figures.
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
use OpenEMR\Core\Header;
use OpenHealthcare\SlidingFee\Context;
use OpenHealthcare\SlidingFee\Domain\Evaluation;
use OpenHealthcare\SlidingFee\Domain\Schedule;
use OpenHealthcare\SlidingFee\Guide;
use OpenHealthcare\SlidingFee\Labels;
use OpenHealthcare\SlidingFee\Repository\AssessmentRepository;
use OpenHealthcare\SlidingFee\Repository\EvaluationRepository;

// It reads payments and adjustments: the same check as the Collections report.
if (!AclMain::aclCheckCore('acct', 'rep_a')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for acct/rep_a: Sliding Fee Discount evaluation', xl('Sliding Fee Discount'));
}
$context = Context::load();
// One report per schedule: a service schedule's visits are those of its categories, the main schedule's all others.
$service = (string)($_GET['service'] ?? Schedule::MAIN);
$schedule = $context->inForce[$service]['schedule'] ?? $context->schedule;
$service = $schedule?->service ?? Schedule::MAIN;
$today = new DateTimeImmutable('today');
$from = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_GET['from'] ?? '')) ?: $today->modify('first day of january this year');
$to = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_GET['to'] ?? '')) ?: $today;
if ($to < $from) {
    [$from, $to] = [$to, $from];
}
$rows = [];
$recorded = ['assessments' => 0, 'declined' => 0, 'patients' => 0];
if ($schedule !== null) {
    $repo = new EvaluationRepository();
    $visits = array_values(array_filter(
        $repo->visits($from, $to, $schedule->reasons),
        static fn(array $v): bool => $context->scheduleFor($v['category'])?->service === $service,
    ));
    $pids = array_values(array_unique(array_map(static fn(array $v): int => $v['pid'], $visits)));
    $rows = Evaluation::byClass($schedule, $context->guidelines, $visits, (new AssessmentRepository())->forPatients($pids));
    $recorded = $repo->assessmentsRecorded($from, $to);
}

if (($_GET['format'] ?? '') === 'csv' && $schedule !== null) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sliding-fee-evaluation-' . ($schedule->isMain() ? '' : $schedule->service . '-') . $from->format('Y-m-d') . '-to-' . $to->format('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        exit;
    }
    fputcsv($out, ['Sliding fee discount program evaluation', $from->format('Y-m-d') . ' to ' . $to->format('Y-m-d'), 'guidelines ' . $schedule->year . ' ' . $schedule->region, $schedule->isMain() ? 'main schedule' : $schedule->name . ' schedule'], ',', '"', '');
    fputcsv($out, ['Pay class', 'Patients', 'Visits', 'Charges at full fee', 'Sliding fee discounts posted'], ',', '"', '');
    foreach ($rows as $class => $r) {
        fputcsv($out, [Labels::payClass($class, $schedule), $r['patients'], $r['visits'], number_format($r['fees'], 2, '.', ''), number_format($r['sliding'], 2, '.', '')], ',', '"', '');
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<?php // The module's own text is written in English. ?>
<html lang="en">
<head>
    <title><?php echo xlt('Sliding fee evaluation'); ?></title>
    <?php Header::setupHeader(); ?>
    <?php echo \OpenHealthcare\SlidingFee\Contrast::style(); ?>
    <style>/* Links in running text are underlined, not told apart by colour alone (WCAG 1.4.1). */ p a, li a { text-decoration: underline; }</style>
</head>
<body class="body_top">
<div class="container-fluid py-3">
    <h1 class="h4"><?php echo xlt('Sliding fee discount program evaluation'); ?></h1>
    <p class="small mb-2"><a href="<?php echo attr(Guide::url()); ?>" target="_blank" rel="noopener"><?php echo xlt('Help'); ?><span class="sr-only"> <?php echo xlt('(opens in a new window)'); ?></span></a></p>
    <p class="small text-muted"><?php echo xlt('Utilization within each pay class, and at or below 100% of the guidelines, as HRSA Chapter 9 element l asks the health center to collect and evaluate at least once every three years, with other data such as patient surveys. Each visit is placed in the pay class of the assessment in force on its date, on the schedule in use now.'); ?>
        <a href="<?php echo attr(Context::url('index.php')); ?>"><?php echo xlt('Back'); ?></a></p>
    <?php if ($schedule === null) { ?>
        <div class="alert alert-warning"><?php echo xlt('The clinic has not set up its sliding fee schedule yet.'); ?></div>
    <?php } else { ?>
        <?php if (count($context->inForce) > 1) { ?>
        <ul class="nav nav-tabs mb-3" id="schedules">
            <?php foreach ($context->inForce as $key => $entry) { ?>
                <li class="nav-item"><a class="nav-link <?php echo $key === $service ? 'active' : ''; ?>" href="<?php echo attr(Context::url('evaluation.php', ['service' => $key, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')])); ?>"><?php echo $key === Schedule::MAIN ? xlt('Main schedule') : text($entry['schedule']->name); ?></a></li>
            <?php } ?>
        </ul>
        <?php } ?>
        <form method="get" class="form-inline mb-3">
            <input type="hidden" name="service" value="<?php echo attr($service); ?>">
            <label class="mr-2" for="from"><?php echo xlt('From'); ?></label>
            <input type="date" class="form-control form-control-sm mr-2" id="from" name="from" value="<?php echo attr($from->format('Y-m-d')); ?>">
            <label class="mr-2" for="to"><?php echo xlt('To'); ?></label>
            <input type="date" class="form-control form-control-sm mr-2" id="to" name="to" value="<?php echo attr($to->format('Y-m-d')); ?>">
            <button class="btn btn-sm btn-primary mr-2" type="submit"><?php echo xlt('Show'); ?></button>
            <a class="btn btn-sm btn-outline-secondary" href="<?php echo attr(Context::url('evaluation.php', ['service' => $service, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'format' => 'csv'])); ?>"><?php echo xlt('Download CSV'); ?></a>
        </form>
        <table class="table table-sm w-auto">
            <thead><tr><th><?php echo xlt('Pay class'); ?></th><th class="text-right"><?php echo xlt('Patients'); ?></th><th class="text-right"><?php echo xlt('Visits'); ?></th>
                <th class="text-right"><?php echo xlt('Charges at full fee'); ?></th><th class="text-right"><?php echo xlt('Sliding fee discounts posted'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $class => $r) { ?>
                <tr data-class="<?php echo attr($class); ?>">
                    <td><?php echo text(Labels::payClass($class, $schedule)); ?></td>
                    <td class="text-right"><?php echo text((string)$r['patients']); ?></td>
                    <td class="text-right"><?php echo text((string)$r['visits']); ?></td>
                    <td class="text-right"><?php echo text(Labels::money($r['fees'])); ?></td>
                    <td class="text-right"><?php echo text(Labels::money($r['sliding'])); ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <p class="small"><?php echo text(sprintf(xl('Assessments recorded in the period: %1$s, for %2$s patients; %3$s declined to give income.'), $recorded['assessments'], $recorded['patients'], $recorded['declined'])); ?></p>
    <?php } ?>
</div>
</body>
</html>
