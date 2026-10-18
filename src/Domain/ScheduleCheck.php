<?php

/**
 * Checks a sliding fee discount schedule against HRSA's Health Center Program
 * Compliance Manual, Chapter 9 (element c, structure; element e, current guidelines;
 * element l and Chapter 19, board approval and evaluation every three years). An error
 * is a requirement the schedule doesn't meet; a warning is something to check by hand.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\SlidingFee\Domain;

final class ScheduleCheck
{
    public const ERROR = 'error';
    public const WARNING = 'warning';

    /**
     * @param list<float> $fees the clinic's fee schedule (standard prices), for the nominal charge check
     * @return list<array{level: string, message: string}> errors first
     */
    public static function run(Schedule $s, PovertyGuidelines $guidelines, array $fees, \DateTimeImmutable $today): array
    {
        $errors = [];
        $warnings = [];

        if (!$guidelines->has($s->year, $s->region)) {
            $errors[] = sprintf(xl('The %1$s poverty guidelines for this region are not loaded.'), $s->year);
        } elseif (($latest = $guidelines->latestYear($s->region)) !== null && $latest > $s->year) {
            $warnings[] = sprintf(xl('The schedule uses the %1$s guidelines; the %2$s guidelines are loaded (element e asks for the most recent).'), $s->year, $latest);
        }

        $classes = $s->classes;
        $labels = array_column($classes, 'label');
        if (count($labels) !== count(array_unique($labels))) {
            $errors[] = xl('Two pay classes have the same name.');
        }
        $bounds = array_column($classes, 'upTo');
        if (count($bounds) !== count(array_unique(array_map('strval', $bounds)))) {
            $errors[] = xl('Two pay classes end at the same percentage.');
        }
        foreach ($classes as $c) {
            if ($c['upTo'] <= 100) {
                $errors[] = sprintf(xl('Pay class %1$s ends at or below 100%%: incomes at or below 100%% get the full discount or the nominal charge, not a pay class.'), $c['label']);
            }
            if ($c['method'] === Schedule::PERCENT && ($c['value'] < 0 || $c['value'] > 100)) {
                $errors[] = sprintf(xl('Pay class %1$s takes off %2$s%%: a percentage off is between 0 and 100.'), $c['label'], self::number($c['value']));
            }
            if ($c['method'] === Schedule::FLAT && $c['value'] < 0) {
                $errors[] = sprintf(xl('Pay class %1$s has a negative charge.'), $c['label']);
            }
        }

        $middle = array_values(array_filter($classes, static fn(array $c): bool => $c['upTo'] > 100 && $c['upTo'] <= 200));
        if (count($middle) < 3) {
            $errors[] = sprintf(xl('There are %1$s pay classes between 101%% and 200%%; HRSA requires at least three (element c).'), count($middle));
        }
        $last = $classes === [] ? 100.0 : max($bounds);
        if ($last < 200) {
            $errors[] = sprintf(xl('The last pay class ends at %1$s%%: incomes above that and up to 200%% would get no discount (element c).'), self::number($last));
        }
        $above = array_values(array_filter($classes, static fn(array $c): bool => $c['upTo'] > 200));
        if ($above !== [] && !$s->otherFundingAbove200) {
            $errors[] = xl('A pay class goes above 200%: HRSA allows discounts there only when other funding pays for them (element c). Tick that box if it does.');
        }

        for ($i = 1; $i < count($classes); $i++) {
            $prev = $classes[$i - 1];
            $next = $classes[$i];
            if ($prev['method'] !== $next['method']) {
                $warnings[] = sprintf(xl('Pay classes %1$s and %2$s use different methods (a percentage and a flat charge); check by hand that the discount shrinks as income rises.'), $prev['label'], $next['label']);
            } elseif ($prev['method'] === Schedule::PERCENT && $next['value'] > $prev['value']) {
                $errors[] = sprintf(xl('Pay class %1$s takes off more than %2$s, which has lower incomes: discounts must shrink as income rises.'), $next['label'], $prev['label']);
            } elseif ($prev['method'] === Schedule::FLAT && $next['value'] < $prev['value']) {
                $errors[] = sprintf(xl('Pay class %1$s charges less than %2$s, which has lower incomes: discounts must shrink as income rises.'), $next['label'], $prev['label']);
            }
        }
        if (count($middle) >= 3 && count(array_unique(array_map(static fn(array $c): string => $c['method'] . ':' . $c['value'], $middle))) === 1) {
            $warnings[] = xl('Every pay class between 101% and 200% has the same discount; HRSA expects discounts that adjust with income (element c).');
        }

        if ($s->atOrBelow100 === Schedule::NOMINAL) {
            $errors = array_merge($errors, self::nominal($s, $fees, $warnings));
        }

        if ($s->reasons === []) {
            $warnings[] = xl('No Adjustment Reason is marked as a sliding fee discount, so the evaluation report can find none of the discounts posted.');
        }
        if ($s->approvedOn === null) {
            $warnings[] = xl('Record when the board approved the schedule: HRSA asks the board to approve it, and to evaluate the program at least once every three years (element l, Chapter 19).');
        } elseif (new \DateTimeImmutable($s->approvedOn) < $today->modify('-3 years')) {
            $warnings[] = sprintf(xl('The board approved the schedule on %1$s, more than three years ago: the program is due for its evaluation (element l).'), $s->approvedOn);
        }

        return array_merge(
            array_map(static fn(string $m): array => ['level' => self::ERROR, 'message' => $m], $errors),
            array_map(static fn(string $m): array => ['level' => self::WARNING, 'message' => $m], $warnings),
        );
    }

    /**
     * The nominal charge must be less than what a patient in the first class above 100% pays.
     *
     * @param list<float>  $fees
     * @param list<string> $warnings added to
     * @return list<string> errors
     */
    private static function nominal(Schedule $s, array $fees, array &$warnings): array
    {
        $errors = [];
        if ($s->nominal <= 0) {
            $warnings[] = xl('The nominal charge is $0, which is a full discount; choose the full discount instead.');
            return $errors;
        }
        $first = $s->classes[0] ?? null;
        if ($first === null) {
            return $errors;
        }
        if ($first['method'] === Schedule::FLAT) {
            if ($s->nominal >= $first['value']) {
                $errors[] = sprintf(xl('The nominal charge (%1$s) must be less than the charge of pay class %2$s (%3$s) (element c).'), self::money($s->nominal), $first['label'], self::money($first['value']));
            }
            return $errors;
        }
        $share = (100 - $first['value']) / 100;
        if ($share <= 0) {
            $errors[] = sprintf(xl('Pay class %1$s takes off 100%%, so no nominal charge can be less than what it pays (element c).'), $first['label']);
            return $errors;
        }
        $threshold = $s->nominal / $share;
        $under = array_values(array_filter($fees, static fn(float $fee): bool => $fee > 0 && $fee <= $threshold));
        if ($under !== []) {
            $warnings[] = sprintf(
                xl('For a fee of %1$s or less, the nominal charge (%2$s) is not less than what pay class %3$s pays. %4$s of the fees in your fee schedule are that low; check that a visit is never charged only those.'),
                self::money($threshold),
                self::money($s->nominal),
                $first['label'],
                count($under),
            );
        }
        return $errors;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private static function money(float $value): string
    {
        return '$' . number_format($value, 2);
    }
}
