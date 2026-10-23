<?php

/**
 * One income assessment of a patient (HRSA Chapter 9, element g asks for a record of
 * each assessment and re-assessment), and which one is in force on a date.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\SlidingFee\Domain;

final class Assessment
{
    public const DOCUMENTS = 'documents';
    public const SELF_DECLARED = 'self_declared';
    public const DECLINED = 'declined';
    public const METHODS = [self::DOCUMENTS, self::SELF_DECLARED, self::DECLINED];

    /** The family's income and size: the usual basis. */
    public const FAMILY = 'family';
    /** The patient's own resources: an unemancipated minor seeking confidential services (Title X, 42 CFR 59.2). */
    public const OWN_RESOURCES = 'own_resources';
    /** Counted as from a low-income family by the project director, unable for good reasons to pay (Title X, 42 CFR 59.2). */
    public const DIRECTOR = 'director';
    public const BASES = [self::FAMILY, self::OWN_RESOURCES, self::DIRECTOR];

    /**
     * @param string $classLabel Schedule::AT_OR_BELOW_100, a pay class label or Schedule::NO_DISCOUNT, as assessed
     * @param string $basis      FAMILY, OWN_RESOURCES or DIRECTOR
     */
    public function __construct(
        public readonly int $id,
        public readonly int $pid,
        public readonly \DateTimeImmutable $assessedOn,
        public readonly string $method,
        public readonly ?float $annualIncome,
        public readonly ?int $familySize,
        public readonly ?float $percent,
        public readonly string $classLabel,
        public readonly string $basis = self::FAMILY,
    ) {
    }

    /**
     * This assessment placed on a schedule: the percentage and class from the income and family size
     * recorded, against the schedule's guidelines year and region, so a new year's guidelines apply
     * to everyone once the clinic adopts them. A declined assessment, or one without figures, is unchanged.
     * On a Title X schedule, a patient the project director counts as low-income is placed at or below
     * 100% whatever the income (42 CFR 59.2); the percentage is kept as recorded.
     */
    public function on(Schedule $schedule, PovertyGuidelines $guidelines): self
    {
        if ($this->method === self::DECLINED || $this->annualIncome === null || $this->familySize === null) {
            return $this;
        }
        $percent = $guidelines->percent($this->annualIncome, $this->familySize, $schedule->year, $schedule->region);
        if ($percent === null) {
            return $this;
        }
        $class = $this->basis === self::DIRECTOR && $schedule->isTitleX() ? Schedule::AT_OR_BELOW_100 : $schedule->classFor($percent);
        return new self($this->id, $this->pid, $this->assessedOn, $this->method, $this->annualIncome, $this->familySize, $percent, $class, $this->basis);
    }

    public function expiresOn(int $months): \DateTimeImmutable
    {
        return $this->assessedOn->setTime(0, 0)->modify("+$months months");
    }

    /**
     * The assessment in force on a day: the latest made on or before it that hasn't expired.
     *
     * @param list<self> $assessments one patient's
     */
    public static function inForce(array $assessments, \DateTimeImmutable $day, int $months): ?self
    {
        $day = $day->setTime(0, 0);
        $best = null;
        foreach ($assessments as $a) {
            if ($a->assessedOn->setTime(0, 0) > $day || $a->expiresOn($months) <= $day) {
                continue;
            }
            if ($best === null || $a->assessedOn > $best->assessedOn || ($a->assessedOn == $best->assessedOn && $a->id > $best->id)) {
                $best = $a;
            }
        }
        return $best;
    }
}
