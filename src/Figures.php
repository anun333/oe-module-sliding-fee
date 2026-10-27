<?php

/**
 * This module's figures for another module's page (Revenue Cycle's dashboard), through openhealthcare-common's figures
 * contract (1.5.0): the sliding fee discounts posted over a period and the visits that have one. Totals only, never a
 * patient; the asking module reads none of this module's tables.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\SlidingFee;

use OpenHealthcare\Common\Contract\Figures\Figure;
use OpenHealthcare\Common\Contract\Figures\FiguresRequest;
use OpenHealthcare\SlidingFee\Repository\EvaluationRepository;

final class Figures
{
    public const DISCOUNTS_POSTED = 'slidingfee.discounts_posted';
    public const VISITS_DISCOUNTED = 'slidingfee.visits_discounted';

    /** Whether the request asks for one of this module's figures. */
    public static function asked(FiguresRequest $request): bool
    {
        return $request->wants(self::DISCOUNTS_POSTED) || $request->wants(self::VISITS_DISCOUNTED);
    }

    /**
     * Answers the request from the schedules' Adjustment Reasons, the evaluation's way.
     *
     * @param list<string> $reasons every schedule in force's reasons (Context::allReasons())
     */
    public static function answer(FiguresRequest $request, array $reasons, EvaluationRepository $repo = new EvaluationRepository()): void
    {
        $totals = $repo->discountTotals($request->from, $request->to, $reasons, $request->facilityId);
        $note = $reasons === []
            ? xl('No Adjustment Reason is marked as a sliding fee discount on a schedule, so none is counted')
            : xl('Patient adjustments with a sliding fee reason, on visits dated in the period');
        $url = Bootstrap::MODULE_INSTALLATION_PATH . '/public/evaluation.php?'
            . http_build_query(['from' => $request->from->format('Y-m-d'), 'to' => $request->to->format('Y-m-d')]);
        $module = xl('Sliding Fee Discount');
        $request->answer(new Figure(self::DISCOUNTS_POSTED, xl('Sliding fee discounts posted'), $totals['amount'], Figure::UNIT_MONEY, $module, $note, $url));
        $request->answer(new Figure(self::VISITS_DISCOUNTED, xl('Visits with a sliding fee discount'), $totals['visits'], Figure::UNIT_COUNT, $module, $note, $url));
    }
}
