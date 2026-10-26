<?php

/**
 * The visits of a period with their charges and the sliding fee discounts posted for
 * them, and the assessments recorded in it: what the evaluation report groups by pay
 * class (HRSA Chapter 9, element l: utilization data within each discount pay class,
 * and at or below 100 percent).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\SlidingFee\Repository;

use OpenEMR\Common\Database\QueryUtils;

final class EvaluationRepository
{
    /**
     * The products sold at the visits of a period (core's drug_sales, sales only), by "pid:encounter", each with
     * whether core marks it as a contraceptive (a CYP factor), for a Title X schedule's charges.
     *
     * @return array<string, list<array{drugId: int, fee: float, contraceptive: bool}>>
     */
    public function productsByVisit(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT s.`pid`, s.`encounter`, s.`drug_id`, SUM(s.`fee`) AS fee, MAX(COALESCE(d.`cyp_factor`, 0)) AS cyp
               FROM `drug_sales` AS s
               JOIN `form_encounter` AS fe ON fe.`pid` = s.`pid` AND fe.`encounter` = s.`encounter`
               LEFT JOIN `drugs` AS d ON d.`drug_id` = s.`drug_id`
              WHERE s.`trans_type` = 1 AND fe.`date` >= ? AND fe.`date` < ?
              GROUP BY s.`pid`, s.`encounter`, s.`drug_id`",
            [$from->format('Y-m-d'), $to->modify('+1 day')->format('Y-m-d')],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['pid'] . ':' . (int)$r['encounter']][] = [
                'drugId' => (int)$r['drug_id'],
                'fee' => (float)$r['fee'],
                'contraceptive' => (float)$r['cyp'] > 0,
            ];
        }
        return $out;
    }

    /**
     * @param list<string> $reasons Adjustment Reasons posted as sliding fee discounts
     * @return list<array{pid: int, encounter: int, date: \DateTimeImmutable, category: int, fees: float, sliding: float}>
     */
    public function visits(\DateTimeImmutable $from, \DateTimeImmutable $to, array $reasons): array
    {
        [$sliding, $slidingBinds] = VisitRepository::slidingRow($reasons, 'a');
        $sql = "SELECT fe.`pid`, fe.`encounter`, fe.`date`, COALESCE(fe.`pc_catid`, 0) AS category,
                (SELECT COALESCE(SUM(b.`fee`), 0) FROM `billing` AS b
                  WHERE b.`pid` = fe.`pid` AND b.`encounter` = fe.`encounter` AND b.`activity` = 1
                    AND b.`code_type` NOT IN ('COPAY', 'TAX')) AS fees,
                (SELECT COALESCE(SUM(a.`adj_amount`), 0) FROM `ar_activity` AS a
                  WHERE a.`pid` = fe.`pid` AND a.`encounter` = fe.`encounter` AND a.`deleted` IS NULL
                    AND $sliding) AS sliding
            FROM `form_encounter` AS fe
            WHERE fe.`date` >= ? AND fe.`date` < ?
            ORDER BY fe.`date`";
        $rows = QueryUtils::fetchRecords($sql, [...$slidingBinds, $from->format('Y-m-d'), $to->modify('+1 day')->format('Y-m-d')]);
        return array_map(static fn(array $r): array => [
            'pid' => (int)$r['pid'],
            'encounter' => (int)$r['encounter'],
            'date' => new \DateTimeImmutable(substr((string)$r['date'], 0, 10)),
            'category' => (int)$r['category'],
            'fees' => (float)$r['fees'],
            'sliding' => (float)$r['sliding'],
        ], $rows);
    }

    /** How many assessments were recorded in the period, and how many of them the patient declined. */
    public function assessmentsRecorded(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $row = QueryUtils::fetchRecords(
            "SELECT COUNT(*) AS n, COALESCE(SUM(`method` = 'declined'), 0) AS declined, COUNT(DISTINCT `pid`) AS patients
               FROM `sliding_fee_assessment` WHERE `assessed_on` >= ? AND `assessed_on` <= ?",
            [$from->format('Y-m-d'), $to->format('Y-m-d')],
        )[0];
        return ['assessments' => (int)$row['n'], 'declined' => (int)$row['declined'], 'patients' => (int)$row['patients']];
    }
}
