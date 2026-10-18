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

    /**
     * @param string $classLabel Schedule::AT_OR_BELOW_100, a pay class label or Schedule::NO_DISCOUNT, as assessed
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
    ) {
    }

    /**
     * This assessment placed on a schedule: the percentage and class from the income and family size
     * recorded, against the schedule's guidelines year and region, so a new year's guidelines apply
     * to everyone once the clinic adopts them. A declined assessment, or one without figures, is unchanged.
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
        return new self($this->id, $this->pid, $this->assessedOn, $this->method, $this->annualIncome, $this->familySize, $percent, $schedule->classFor($percent));
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
