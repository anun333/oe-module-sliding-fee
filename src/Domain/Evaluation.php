<?php

/**
 * Utilization by pay class for the program's evaluation (HRSA Chapter 9, element l):
 * each visit is placed in the pay class of the assessment in force on its date, on the
 * schedule in use; visits with no assessment in force, or a declined one, have rows of
 * their own.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\SlidingFee\Domain;

final class Evaluation
{
    /**
     * @param list<array{pid: int, encounter: int, date: \DateTimeImmutable, fees: float, sliding: float}> $visits
     * @param array<int, list<Assessment>>                                                          $assessments by pid
     * @return array<string, array{patients: int, visits: int, fees: float, sliding: float}> by class, in schedule order:
     *         at or below 100%, the classes, no discount, declined, none
     */
    public static function byClass(Schedule $schedule, PovertyGuidelines $guidelines, array $visits, array $assessments): array
    {
        $order = [Schedule::AT_OR_BELOW_100, ...array_column($schedule->classes, 'label'), Schedule::NO_DISCOUNT, VisitDiscount::DECLINED, VisitDiscount::NONE];
        $rows = array_fill_keys($order, ['patients' => 0, 'visits' => 0, 'fees' => 0.0, 'sliding' => 0.0]);
        $seen = [];
        foreach ($visits as $v) {
            $inForce = Assessment::inForce($assessments[$v['pid']] ?? [], $v['date'], $schedule->reassessMonths);
            $class = match (true) {
                $inForce === null => VisitDiscount::NONE,
                $inForce->method === Assessment::DECLINED => VisitDiscount::DECLINED,
                default => $inForce->on($schedule, $guidelines)->classLabel,
            };
            if (!isset($rows[$class])) {
                $class = VisitDiscount::NONE;
            }
            $rows[$class]['visits']++;
            $rows[$class]['fees'] += $v['fees'];
            $rows[$class]['sliding'] += $v['sliding'];
            if (!isset($seen[$class][$v['pid']])) {
                $seen[$class][$v['pid']] = true;
                $rows[$class]['patients']++;
            }
        }
        foreach ($rows as $class => $row) {
            $rows[$class]['fees'] = round($row['fees'], 2);
            $rows[$class]['sliding'] = round($row['sliding'], 2);
        }
        return $rows;
    }
}
