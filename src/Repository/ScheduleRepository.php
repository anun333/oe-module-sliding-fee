<?php

/**
 * The sliding fee schedules: each saved version is kept, and the newest of each service is in force.
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
use OpenHealthcare\SlidingFee\Domain\Schedule;

final class ScheduleRepository
{
    /** The main schedule in force. @return array{id: int, schedule: Schedule, savedAt: string}|null */
    public function current(): ?array
    {
        return $this->all()[Schedule::MAIN] ?? null;
    }

    /**
     * Every schedule in force: the newest saved version of each, the main one first. A service
     * schedule takes the program's guidelines and re-assessment period from the main one.
     *
     * @return array<string, array{id: int, schedule: Schedule, savedAt: string}> by service key
     */
    public function all(): array
    {
        $found = [];
        foreach (QueryUtils::fetchRecords('SELECT `id`, `schedule_json`, `saved_at` FROM `sliding_fee_schedule` ORDER BY `id` DESC') as $row) {
            $data = json_decode((string)$row['schedule_json'], true);
            $schedule = Schedule::fromArray(is_array($data) ? $data : []);
            $found[$schedule->service] ??= ['id' => (int)$row['id'], 'schedule' => $schedule, 'savedAt' => (string)$row['saved_at']];
        }
        $main = $found[Schedule::MAIN] ?? null;
        if ($main === null) {
            return [];
        }
        $out = [Schedule::MAIN => $main];
        foreach ($found as $service => $entry) {
            if ($service !== Schedule::MAIN && $entry['schedule']->categories !== []) {
                $out[$service] = ['schedule' => $entry['schedule']->withProgram($main['schedule'])] + $entry;
            }
        }
        return $out;
    }

    /**
     * The visit categories a service schedule can cover: the calendar's patient appointment categories.
     *
     * @return array<int, string> pc_catid => name
     */
    public function visitCategories(): array
    {
        $out = [];
        foreach (QueryUtils::fetchRecords('SELECT `pc_catid`, `pc_catname` FROM `openemr_postcalendar_categories` WHERE `pc_cattype` = 0 ORDER BY `pc_catname`') as $r) {
            $out[(int)$r['pc_catid']] = (string)$r['pc_catname'];
        }
        return $out;
    }

    /**
     * The clinic's active products, for a Title X schedule's list of contraceptive products, each with
     * whether core already marks it as a contraceptive (a CYP factor).
     *
     * @return list<array{drugId: int, name: string, contraceptive: bool}>
     */
    public function products(): array
    {
        return array_map(static fn(array $r): array => [
            'drugId' => (int)$r['drug_id'],
            'name' => (string)$r['name'],
            'contraceptive' => (float)$r['cyp_factor'] > 0,
        ], QueryUtils::fetchRecords('SELECT `drug_id`, `name`, `cyp_factor` FROM `drugs` WHERE `active` = 1 ORDER BY `name`, `drug_id`'));
    }

    public function save(Schedule $schedule, int $userId): int
    {
        return (int)QueryUtils::sqlInsert(
            'INSERT INTO `sliding_fee_schedule` (`schedule_json`, `saved_by`) VALUES (?, ?)',
            [json_encode($schedule->toArray(), JSON_THROW_ON_ERROR), $userId],
        );
    }

    /**
     * The fees in the clinic's fee schedule at the default price level, for the nominal charge check.
     *
     * @return list<float>
     */
    public function fees(): array
    {
        $sql = "SELECT DISTINCT p.`pr_price` AS fee FROM `prices` AS p
            JOIN `list_options` AS lo ON lo.`list_id` = 'pricelevel' AND lo.`option_id` = p.`pr_level` AND lo.`is_default` = 1
            WHERE p.`pr_selector` = '' AND p.`pr_price` > 0";
        return array_map(static fn(array $r): float => (float)$r['fee'], QueryUtils::fetchRecords($sql));
    }

    /**
     * The Adjustment Reasons a clinic can mark as sliding fee discounts: those that change the balance.
     *
     * @return list<array{id: string, title: string}>
     */
    public function adjustmentReasons(): array
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT `option_id`, `title` FROM `list_options` WHERE `list_id` = 'adjreason' AND `activity` = 1 AND `option_value` = 1 ORDER BY `seq`, `title`",
        );
        return array_map(static fn(array $r): array => ['id' => (string)$r['option_id'], 'title' => (string)$r['title']], $rows);
    }
}
