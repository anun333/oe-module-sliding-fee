<?php

/**
 * The Sliding Fee Discount card on the patient dashboard: the patient's pay class and
 * when they are due for re-assessment. Core shows a card only to users who pass its
 * access check, here patients/demo, the same as the dashboard's other cards.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\SlidingFee\Card;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Events\Patient\Summary\Card\CardModel;
use OpenHealthcare\SlidingFee\Bootstrap;
use OpenHealthcare\SlidingFee\Context;
use OpenHealthcare\SlidingFee\Domain\Assessment;
use OpenHealthcare\SlidingFee\Labels;
use OpenHealthcare\SlidingFee\Repository\AssessmentRepository;

final class SlidingFeeCard extends CardModel
{
    public function __construct(int $pid)
    {
        parent::__construct([
            'acl' => ['patients', 'demo'],
            'initiallyCollapsed' => false,
            'add' => false,
            'edit' => false,
            'collapse' => true,
            'templateFile' => '@' . Bootstrap::TWIG_NAMESPACE . '/card.html.twig',
            'identifier' => 'sliding_fee_discount',
            'title' => xl('Sliding Fee Discount'),
            'templateVariables' => self::variables($pid),
        ]);
    }

    /** @return array<string, mixed> */
    private static function variables(int $pid): array
    {
        $context = Context::load();
        $schedule = $context->schedule;
        $vars = [
            'hasSchedule' => $schedule !== null,
            'status' => '',
            'detail' => '',
            'due' => '',
            'overdue' => false,
            'assessUrl' => AclMain::aclCheckCore('patients', 'demo', '', 'write') ? Context::url('patient.php', ['pid' => $pid]) : '',
        ];
        if ($schedule === null) {
            return $vars;
        }
        $today = new \DateTimeImmutable('today');
        $assessments = (new AssessmentRepository())->forPatient($pid);
        $inForce = Assessment::inForce($assessments, $today, $schedule->reassessMonths);
        if ($inForce === null) {
            $last = $assessments[0] ?? null;
            $vars['status'] = $last === null ? xl('No income assessment') : xl('Re-assessment due');
            $vars['detail'] = $last === null ? '' : xl('Last assessed') . ' ' . oeFormatShortDate($last->assessedOn->format('Y-m-d'));
            $vars['overdue'] = $last !== null;
            return $vars;
        }
        $placed = $inForce->on($schedule, $context->guidelines);
        $vars['status'] = Labels::payClass($inForce->method === Assessment::DECLINED ? \OpenHealthcare\SlidingFee\Domain\VisitDiscount::DECLINED : $placed->classLabel, $schedule);
        $vars['detail'] = trim(Labels::percent($placed->percent) . ' · ' . Labels::method($inForce->method) . ' · '
            . xl('assessed') . ' ' . oeFormatShortDate($inForce->assessedOn->format('Y-m-d')), ' ·');
        $vars['due'] = oeFormatShortDate($inForce->expiresOn($schedule->reassessMonths)->format('Y-m-d'));
        return $vars;
    }
}
