<?php

/**
 * What every page needs: the poverty guidelines, the main schedule in force, and every service schedule.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\SlidingFee;

use OpenHealthcare\SlidingFee\Domain\PovertyGuidelines;
use OpenHealthcare\SlidingFee\Domain\Schedule;
use OpenHealthcare\SlidingFee\Repository\ScheduleRepository;
use OpenHealthcare\Common\OpenEmr;

final class Context
{
    private function __construct(
        public readonly PovertyGuidelines $guidelines,
        public readonly ?Schedule $schedule,
        public readonly ?int $scheduleId,
        public readonly ?string $savedAt,
        public readonly ScheduleRepository $schedules,
        /** @var array<string, array{id: int, schedule: Schedule, savedAt: string}> every schedule in force, by service key */
        public readonly array $inForce = [],
    ) {
    }

    public static function load(): self
    {
        $schedules = new ScheduleRepository();
        $all = $schedules->all();
        $current = $all[Schedule::MAIN] ?? null;
        return new self(
            PovertyGuidelines::fromFile(dirname(__DIR__) . '/data/poverty-guidelines.json'),
            $current['schedule'] ?? null,
            $current['id'] ?? null,
            $current['savedAt'] ?? null,
            $schedules,
            $all,
        );
    }

    /** The schedule for a visit of this category: a service schedule that names it, else the main one. */
    public function scheduleFor(int $category): ?Schedule
    {
        return Schedule::forCategory(array_values(array_column($this->inForce, 'schedule')), $category);
    }

    /**
     * Every Adjustment Reason any schedule posts discounts under, so a visit's discounts already
     * posted are counted whichever schedule it falls under.
     *
     * @return list<string>
     */
    public function allReasons(): array
    {
        $reasons = [];
        foreach ($this->inForce as $entry) {
            $reasons = [...$reasons, ...$entry['schedule']->reasons];
        }
        return array_values(array_unique($reasons));
    }

    public static function url(string $page, array $query = []): string
    {
        return OpenEmr::webRoot() . Bootstrap::MODULE_INSTALLATION_PATH . '/public/' . $page
            . ($query === [] ? '' : '?' . http_build_query($query));
    }
}
