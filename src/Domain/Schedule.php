<?php

/**
 * A sliding fee discount schedule: at or below 100% of the poverty guidelines a full
 * discount or a flat nominal charge, then pay classes up to a percentage of the
 * guidelines, each a percentage off the fee or a flat charge per visit. Above the last
 * class there is no discount.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\SlidingFee\Domain;

final class Schedule
{
    public const FULL = 'full';
    public const NOMINAL = 'nominal';
    public const PERCENT = 'percent';
    public const FLAT = 'flat';

    public const AT_OR_BELOW_100 = 'at_or_below_100';
    public const NO_DISCOUNT = 'no_discount';

    /**
     * @param string                                                     $atOrBelow100 FULL or NOMINAL
     * @param float                                                      $nominal      the flat charge per visit when NOMINAL
     * @param list<array{label: string, upTo: float, method: string, value: float}> $classes in order; upTo is the
     *        highest percentage of the guidelines in the class; value is the percentage off (PERCENT) or the charge (FLAT)
     * @param list<string>                                               $reasons      Adjustment Reasons (option ids) posted as sliding fee discounts
     */
    public function __construct(
        public readonly string $region,
        public readonly int $year,
        public readonly string $atOrBelow100,
        public readonly float $nominal,
        public readonly array $classes,
        public readonly int $reassessMonths = 12,
        public readonly array $reasons = [],
        public readonly bool $otherFundingAbove200 = false,
        public readonly ?string $approvedOn = null,
    ) {
    }

    /**
     * The class for a percentage of the guidelines: AT_OR_BELOW_100, a class label, or NO_DISCOUNT.
     */
    public function classFor(float $percent): string
    {
        if ($percent <= 100) {
            return self::AT_OR_BELOW_100;
        }
        foreach ($this->classes as $class) {
            if ($percent <= $class['upTo']) {
                return $class['label'];
            }
        }
        return self::NO_DISCOUNT;
    }

    /** @return array{label: string, upTo: float, method: string, value: float}|null */
    public function classNamed(string $label): ?array
    {
        foreach ($this->classes as $class) {
            if ($class['label'] === $label) {
                return $class;
            }
        }
        return null;
    }

    /**
     * What a patient in a class is charged for a visit whose fees total $fees, before insurance.
     */
    public function chargeFor(string $class, float $fees): float
    {
        $fees = max(0.0, $fees);
        if ($class === self::AT_OR_BELOW_100) {
            return $this->atOrBelow100 === self::NOMINAL ? min($this->nominal, $fees) : 0.0;
        }
        $row = $this->classNamed($class);
        if ($row === null) {
            return $fees;
        }
        $charge = $row['method'] === self::FLAT ? $row['value'] : $fees * (100 - $row['value']) / 100;
        return round(min(max(0.0, $charge), $fees), 2);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'region' => $this->region,
            'year' => $this->year,
            'atOrBelow100' => $this->atOrBelow100,
            'nominal' => $this->nominal,
            'classes' => $this->classes,
            'reassessMonths' => $this->reassessMonths,
            'reasons' => $this->reasons,
            'otherFundingAbove200' => $this->otherFundingAbove200,
            'approvedOn' => $this->approvedOn,
        ];
    }

    /**
     * @param array<mixed> $data as toArray() writes it, or as the schedule form posts it
     */
    public static function fromArray(array $data): self
    {
        $classes = [];
        foreach (is_array($data['classes'] ?? null) ? $data['classes'] : [] as $class) {
            if (!is_array($class) || trim((string)($class['label'] ?? '')) === '' || !is_numeric($class['upTo'] ?? null)) {
                continue;
            }
            $classes[] = [
                'label' => trim((string)$class['label']),
                'upTo' => (float)$class['upTo'],
                'method' => ($class['method'] ?? '') === self::FLAT ? self::FLAT : self::PERCENT,
                'value' => is_numeric($class['value'] ?? null) ? (float)$class['value'] : 0.0,
            ];
        }
        usort($classes, static fn(array $a, array $b): int => $a['upTo'] <=> $b['upTo']);
        $reasons = array_values(array_filter(
            array_map(static fn($r): string => trim((string)$r), is_array($data['reasons'] ?? null) ? $data['reasons'] : []),
            static fn(string $r): bool => $r !== '',
        ));
        $approved = trim((string)($data['approvedOn'] ?? ''));
        return new self(
            in_array($data['region'] ?? null, PovertyGuidelines::REGIONS, true) ? (string)$data['region'] : 'contiguous',
            is_numeric($data['year'] ?? null) ? (int)$data['year'] : 0,
            ($data['atOrBelow100'] ?? '') === self::NOMINAL ? self::NOMINAL : self::FULL,
            is_numeric($data['nominal'] ?? null) ? (float)$data['nominal'] : 0.0,
            $classes,
            is_numeric($data['reassessMonths'] ?? null) ? max(1, (int)$data['reassessMonths']) : 12,
            $reasons,
            !empty($data['otherFundingAbove200']),
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $approved) ? $approved : null,
        );
    }
}
