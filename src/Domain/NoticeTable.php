<?php

/**
 * The discounts table on Clinic Forms' sliding fee notice, filled from the schedule in force:
 * for each household size, the yearly income up to which the full discount (or the nominal
 * charge) applies, which is 100% of the poverty guideline, and up to which a partial discount
 * applies, the highest pay class's percentage of it. The notice's columns are those three.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\SlidingFee\Domain;

final class NoticeTable
{
    /** Clinic Forms' notice and the table it names (data/packs/us/documents/sliding-fee-notice.json). */
    public const DOCUMENT = 'sliding-fee-notice';
    public const TABLE = 'discounts';
    /** The household sizes the notice has rows for. */
    public const SIZES = 8;

    /**
     * @return ?list<list<string>> household size, full discount up to, partial discount up to; null when the
     *         schedule's guidelines aren't loaded or it has no pay classes
     */
    public static function rows(Schedule $schedule, PovertyGuidelines $guidelines): ?array
    {
        $top = max(array_map(static fn(array $c): float => (float)$c['upTo'], $schedule->classes ?: [['upTo' => 0]]));
        if ($top <= 100 || !$guidelines->has($schedule->year, $schedule->region)) {
            return null;
        }
        $rows = [];
        for ($size = 1; $size <= self::SIZES; $size++) {
            $guideline = (int)$guidelines->guideline($schedule->year, $schedule->region, $size);
            // "Up to": a partial limit rounds down, so a household right at it is never told too much.
            $rows[] = [(string)$size, self::money($guideline), self::money((int)floor($guideline * $top / 100))];
        }
        return $rows;
    }

    /** Where the figures come from, in the notice's language. */
    public static function note(Schedule $schedule, string $language): string
    {
        return $language === 'es'
            ? sprintf('Según nuestra escala vigente, con las guías de pobreza del HHS de %d.', $schedule->year)
            : sprintf('From our sliding fee schedule in force, on the %d HHS poverty guidelines.', $schedule->year);
    }

    private static function money(int $dollars): string
    {
        return '$' . number_format($dollars);
    }
}
