<?php

/**
 * The sliding fee schedule: each saved version is kept, and the newest is in force.
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
    /** @return array{id: int, schedule: Schedule, savedAt: string}|null */
    public function current(): ?array
    {
        $rows = QueryUtils::fetchRecords('SELECT `id`, `schedule_json`, `saved_at` FROM `sliding_fee_schedule` ORDER BY `id` DESC LIMIT 1');
        $row = $rows[0] ?? null;
        if ($row === null) {
            return null;
        }
        $data = json_decode((string)$row['schedule_json'], true);
        return ['id' => (int)$row['id'], 'schedule' => Schedule::fromArray(is_array($data) ? $data : []), 'savedAt' => (string)$row['saved_at']];
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
