<?php

/**
 * A visit's money as the discount needs it: the fee sheet's charges at full fee, and what
 * insurers and the patient have paid and had adjusted, from billing and ar_activity, the
 * same tables the posting pages read.
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

final class VisitRepository
{
    /**
     * @param list<string> $reasons Adjustment Reasons posted as sliding fee discounts
     * @return array{feId: int, date: \DateTimeImmutable, category: int, fees: float, insurancePaid: float, insuranceAdjusted: float,
     *               patientPaid: float, patientAdjusted: float, slidingAdjusted: float, insured: bool, settled: bool}|null
     */
    public function visit(int $pid, int $encounter, array $reasons): ?array
    {
        $fe = QueryUtils::fetchRecords(
            'SELECT `id`, `date`, COALESCE(`pc_catid`, 0) AS category, COALESCE(`last_level_closed`, 0) AS closed FROM `form_encounter` WHERE `pid` = ? AND `encounter` = ?',
            [$pid, $encounter],
        )[0] ?? null;
        if ($fe === null) {
            return null;
        }
        $date = new \DateTimeImmutable(substr((string)$fe['date'], 0, 10));
        $billing = QueryUtils::fetchRecords(
            "SELECT
                COALESCE(SUM(CASE WHEN `code_type` NOT IN ('COPAY', 'TAX') THEN `fee` ELSE 0 END), 0) AS fees,
                COALESCE(SUM(CASE WHEN `code_type` = 'COPAY' THEN -`fee` ELSE 0 END), 0) AS copays
               FROM `billing` WHERE `pid` = ? AND `encounter` = ? AND `activity` = 1",
            [$pid, $encounter],
        )[0];
        $reasonIn = $reasons === [] ? "''" : implode(', ', array_fill(0, count($reasons), '?'));
        $posted = QueryUtils::fetchRecords(
            "SELECT
                COALESCE(SUM(CASE WHEN `payer_type` > 0 THEN `pay_amount` ELSE 0 END), 0) AS ins_paid,
                COALESCE(SUM(CASE WHEN `payer_type` > 0 THEN `adj_amount` ELSE 0 END), 0) AS ins_adj,
                COALESCE(SUM(CASE WHEN `payer_type` = 0 THEN `pay_amount` ELSE 0 END), 0) AS pt_paid,
                COALESCE(SUM(CASE WHEN `payer_type` = 0 AND `memo` NOT IN ($reasonIn) THEN `adj_amount` ELSE 0 END), 0) AS pt_adj,
                COALESCE(SUM(CASE WHEN `memo` IN ($reasonIn) THEN `adj_amount` ELSE 0 END), 0) AS sliding
               FROM `ar_activity` WHERE `pid` = ? AND `encounter` = ? AND `deleted` IS NULL",
            [...$reasons, ...$reasons, $pid, $encounter],
        )[0];
        $levels = $this->insuranceLevels($pid, $date);
        return [
            'feId' => (int)$fe['id'],
            'date' => $date,
            'category' => (int)$fe['category'],
            'fees' => (float)$billing['fees'],
            'insurancePaid' => (float)$posted['ins_paid'],
            'insuranceAdjusted' => (float)$posted['ins_adj'],
            'patientPaid' => (float)$posted['pt_paid'] + (float)$billing['copays'],
            'patientAdjusted' => (float)$posted['pt_adj'],
            'slidingAdjusted' => (float)$posted['sliding'],
            'insured' => $levels > 0,
            'settled' => $levels === 0 || (int)$fe['closed'] >= $levels,
        ];
    }

    /**
     * Each patient's latest visit on or after a day, for the re-assessments due list.
     *
     * @return array<int, \DateTimeImmutable> pid => date
     */
    public function lastVisitsSince(\DateTimeImmutable $since): array
    {
        $out = [];
        $rows = QueryUtils::fetchRecords(
            'SELECT `pid`, MAX(`date`) AS last_visit FROM `form_encounter` WHERE `date` >= ? GROUP BY `pid`',
            [$since->format('Y-m-d')],
        );
        foreach ($rows as $r) {
            $out[(int)$r['pid']] = new \DateTimeImmutable(substr((string)$r['last_visit'], 0, 10));
        }
        return $out;
    }

    /**
     * @return list<array{encounter: int, date: string, reason: string}> newest first
     */
    public function recent(int $pid, int $limit = 10): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT `encounter`, `date`, `reason` FROM `form_encounter` WHERE `pid` = ? ORDER BY `date` DESC, `encounter` DESC LIMIT ' . max(1, $limit),
            [$pid],
        );
        return array_map(static fn(array $r): array => [
            'encounter' => (int)$r['encounter'],
            'date' => substr((string)$r['date'], 0, 10),
            'reason' => (string)($r['reason'] ?? ''),
        ], $rows);
    }

    /** How many insurance levels (primary, secondary, tertiary) the patient had on a day. */
    private function insuranceLevels(int $pid, \DateTimeImmutable $day): int
    {
        $row = QueryUtils::fetchRecords(
            "SELECT COUNT(DISTINCT `type`) AS n FROM `insurance_data`
              WHERE `pid` = ? AND COALESCE(`provider`, '') NOT IN ('', '0')
                AND (`date` IS NULL OR `date` <= ?) AND (`date_end` IS NULL OR `date_end` >= ?)",
            [$pid, $day->format('Y-m-d'), $day->format('Y-m-d')],
        )[0] ?? ['n' => 0];
        return (int)$row['n'];
    }
}
