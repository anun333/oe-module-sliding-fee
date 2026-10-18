<?php

/**
 * What the pages call pay classes and assessment methods, and how they show money.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\SlidingFee;

use OpenHealthcare\SlidingFee\Domain\Assessment;
use OpenHealthcare\SlidingFee\Domain\Schedule;
use OpenHealthcare\SlidingFee\Domain\VisitDiscount;

final class Labels
{
    public static function payClass(string $class, ?Schedule $schedule = null): string
    {
        return match ($class) {
            Schedule::AT_OR_BELOW_100 => xl('At or below 100% of the guidelines') . ($schedule?->atOrBelow100 === Schedule::NOMINAL
                ? ' (' . xl('nominal charge') . ' ' . self::money($schedule->nominal) . ')'
                : ' (' . xl('full discount') . ')'),
            Schedule::NO_DISCOUNT => xl('Above the schedule: no discount'),
            VisitDiscount::DECLINED => xl('Declined to give income: no discount'),
            VisitDiscount::NONE => xl('No assessment in force'),
            default => xl('Pay class') . ' ' . $class . self::classTerms($class, $schedule),
        };
    }

    public static function method(string $method): string
    {
        return match ($method) {
            Assessment::DOCUMENTS => xl('Documents seen'),
            Assessment::SELF_DECLARED => xl('Self-declared'),
            Assessment::DECLINED => xl('Declined to give income'),
            default => $method,
        };
    }

    public static function money(float $amount): string
    {
        return '$' . number_format($amount, 2);
    }

    public static function percent(?float $percent): string
    {
        return $percent === null ? '' : number_format($percent, 0) . '%';
    }

    private static function classTerms(string $class, ?Schedule $schedule): string
    {
        $row = $schedule?->classNamed($class);
        if ($row === null) {
            return '';
        }
        $terms = $row['method'] === Schedule::FLAT
            ? self::money($row['value']) . ' ' . xl('per visit')
            : rtrim(rtrim(number_format($row['value'], 2, '.', ''), '0'), '.') . '% ' . xl('off');
        return ' (' . xl('up to') . ' ' . rtrim(rtrim(number_format($row['upTo'], 2, '.', ''), '0'), '.') . '%, ' . $terms . ')';
    }
}
