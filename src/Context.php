<?php

/**
 * What every page needs: the poverty guidelines and the schedule in force.
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

final class Context
{
    private function __construct(
        public readonly PovertyGuidelines $guidelines,
        public readonly ?Schedule $schedule,
        public readonly ?int $scheduleId,
        public readonly ?string $savedAt,
        public readonly ScheduleRepository $schedules,
    ) {
    }

    public static function load(): self
    {
        $schedules = new ScheduleRepository();
        $current = $schedules->current();
        return new self(
            PovertyGuidelines::fromFile(dirname(__DIR__) . '/data/poverty-guidelines.json'),
            $current['schedule'] ?? null,
            $current['id'] ?? null,
            $current['savedAt'] ?? null,
            $schedules,
        );
    }

    public static function url(string $page, array $query = []): string
    {
        return \OpenEMR\Core\OEGlobalsBag::getInstance()->getWebRoot() . Bootstrap::MODULE_INSTALLATION_PATH . '/public/' . $page
            . ($query === [] ? '' : '?' . http_build_query($query));
    }
}
