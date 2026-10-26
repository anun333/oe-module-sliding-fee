<?php

/**
 * A sliding fee discount schedule: at or below 100% of the poverty guidelines a full
 * discount or a flat nominal charge, then pay classes up to a percentage of the
 * guidelines, each a percentage off the fee or a flat charge per visit. Above the last
 * class there is no discount.
 *
 * A clinic has a main schedule and may add one per service or delivery method, such as
 * dental (HRSA Compliance Manual Chapter 9, element d), each covering the visit
 * categories it names. The guidelines region and year and the re-assessment period
 * belong to the program, so a service schedule takes them from the main one.
 *
 * Each schedule follows one program's rules: HRSA's Health Center Program (Compliance
 * Manual Chapter 9), or Title X family planning (42 CFR 59.5(a)(7) and (8)): no charge at
 * or below 100%, discounts to 250%, fees that recover reasonable cost above it. A health
 * center with a Title X project gives its family planning visits a service schedule on
 * Title X rules.
 *
 * On Title X rules the discount also covers the visit's contraceptive products: 42 CFR 59.2
 * counts "FDA-approved contraceptive products" among family planning services, and 59.5(a)(7)
 * and (8) apply no charge and the discount to services. A product counts when core marks it as
 * a contraceptive, by a CYP factor (couple-years of protection, which only contraceptive methods
 * have, and the first test core's IPPF statistics report applies), or when the schedule lists it.
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
    /** The service key of the main schedule, which covers every visit no other schedule claims. */
    public const MAIN = 'main';

    public const HRSA = 'hrsa';
    public const TITLE_X = 'title-x';
    public const RULES = [self::HRSA, self::TITLE_X];

    /**
     * @param string                                                     $atOrBelow100 FULL or NOMINAL
     * @param float                                                      $nominal      the flat charge per visit when NOMINAL
     * @param list<array{label: string, upTo: float, method: string, value: float}> $classes in order; upTo is the
     *        highest percentage of the guidelines in the class; value is the percentage off (PERCENT) or the charge (FLAT)
     * @param list<string>                                               $reasons      Adjustment Reasons (option ids) posted as sliding fee discounts
     * @param string                                                     $service      MAIN, or the key of a service schedule
     * @param string                                                     $name         what staff call a service schedule, such as Dental
     * @param list<int>                                                  $categories   the visit categories a service schedule covers
     * @param string                                                     $rules        HRSA or TITLE_X
     * @param list<int>                                                  $contraceptiveProducts on Title X rules, products (drugs.drug_id)
     *        discounted as contraceptives besides those core already marks as contraceptives
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
        public readonly string $service = self::MAIN,
        public readonly string $name = '',
        public readonly array $categories = [],
        public readonly string $rules = self::HRSA,
        public readonly array $contraceptiveProducts = [],
    ) {
    }

    public function isTitleX(): bool
    {
        return $this->rules === self::TITLE_X;
    }

    public function isMain(): bool
    {
        return $this->service === self::MAIN;
    }

    /** This service schedule with the program's guidelines and re-assessment period, from the main schedule. */
    public function withProgram(self $main): self
    {
        return new self($main->region, $main->year, $this->atOrBelow100, $this->nominal, $this->classes, $main->reassessMonths,
            $this->reasons, $this->otherFundingAbove200, $this->approvedOn, $this->service, $this->name, $this->categories, $this->rules,
            $this->contraceptiveProducts);
    }

    /**
     * Whether this schedule discounts a product sold at the visit: only on Title X rules, and only a
     * contraceptive, either one core marks as such or one the schedule lists.
     */
    public function discountsProduct(int $drugId, bool $coreMarksContraceptive): bool
    {
        return $this->isTitleX() && ($coreMarksContraceptive || in_array($drugId, $this->contraceptiveProducts, true));
    }

    /**
     * The fees of the visit's products this schedule discounts.
     *
     * @param list<array{drugId: int, fee: float, contraceptive: bool}> $products
     */
    public function discountedProductFees(array $products): float
    {
        $total = 0.0;
        foreach ($products as $product) {
            if ($this->discountsProduct($product['drugId'], $product['contraceptive'])) {
                $total += $product['fee'];
            }
        }
        return round($total, 2);
    }

    /**
     * The schedule for a visit: the service schedule that names its category, else the main one.
     *
     * @param list<self> $schedules the main schedule and any service schedules, as in force
     */
    public static function forCategory(array $schedules, int $category): ?self
    {
        $main = null;
        foreach ($schedules as $schedule) {
            if ($schedule->isMain()) {
                $main = $schedule;
            } elseif ($category > 0 && in_array($category, $schedule->categories, true)) {
                return $schedule;
            }
        }
        return $main;
    }

    /** A service key from a schedule's name: lowercase letters, digits and dashes, never MAIN. */
    public static function serviceKey(string $name): string
    {
        $key = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        return $key === '' || $key === self::MAIN ? 'service-' . substr(md5($name), 0, 6) : $key;
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
            // Title X allows no charge at all here (42 CFR 59.5(a)(7)), whatever the form holds.
            return $this->atOrBelow100 === self::NOMINAL && !$this->isTitleX() ? min($this->nominal, $fees) : 0.0;
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
            'service' => $this->service,
            'name' => $this->name,
            'categories' => $this->categories,
            'rules' => $this->rules,
            'contraceptiveProducts' => $this->contraceptiveProducts,
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
        $service = (string)($data['service'] ?? self::MAIN);
        $categories = array_values(array_unique(array_filter(
            array_map(static fn($c): int => is_numeric($c) ? (int)$c : 0, is_array($data['categories'] ?? null) ? $data['categories'] : []),
            static fn(int $c): bool => $c > 0,
        )));
        sort($categories);
        $rules = in_array($data['rules'] ?? null, self::RULES, true) ? (string)$data['rules'] : self::HRSA;
        $products = array_values(array_unique(array_filter(
            array_map(static fn($d): int => is_numeric($d) ? (int)$d : 0, is_array($data['contraceptiveProducts'] ?? null) ? $data['contraceptiveProducts'] : []),
            static fn(int $d): bool => $d > 0,
        )));
        sort($products);
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
            preg_match('/^[a-z0-9-]+$/', $service) === 1 ? $service : self::MAIN,
            trim((string)($data['name'] ?? '')),
            $service === self::MAIN ? [] : $categories,
            $rules,
            $rules === self::TITLE_X ? $products : [],
        );
    }
}
