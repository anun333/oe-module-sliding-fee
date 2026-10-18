<?php

/**
 * The sliding fee discount a visit is owed. The schedule's charge for the patient's pay
 * class, worked out on the visit's full fees, is the most the patient pays for it in
 * all. That covers insured patients' copays and deductibles too (HRSA Chapter 9,
 * element k: $80 fee, $60 copay, a class that pays half: the patient pays $40).
 * Insurers are still billed the full fee (Chapter 16), so the discount is an adjustment
 * to what the patient owes, never a lower price.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\SlidingFee\Domain;

final class VisitDiscount
{
    public const NONE = 'none';
    public const DECLINED = 'declined';

    /**
     * @param float $fees            the visit's fee sheet charges at full fee
     * @param float $insurancePaid   payments from insurers
     * @param float $insuranceAdjusted adjustments posted for insurers (contractual write-offs)
     * @param float $patientPaid     payments from the patient, copays included
     * @param float $patientAdjusted other adjustments to what the patient owes
     * @param float $slidingAdjusted sliding fee discounts already posted
     * @param bool  $insured         the patient had insurance on the date of service
     * @param bool  $settled         insurance has finished with the visit (or there is none)
     * @return array{class: string, charge: float, balance: float, post: float, overpaid: float, pending: bool}
     *         class: the pay class, NONE (no assessment in force) or DECLINED; charge: the most the patient pays;
     *         balance: what the patient owes now; post: the discount to post now; overpaid: paid above the charge
     */
    public static function compute(
        Schedule $schedule,
        ?Assessment $assessment,
        float $fees,
        float $insurancePaid,
        float $insuranceAdjusted,
        float $patientPaid,
        float $patientAdjusted,
        float $slidingAdjusted,
        bool $insured,
        bool $settled,
    ): array {
        $balance = round($fees - $insurancePaid - $insuranceAdjusted - $patientPaid - $patientAdjusted - $slidingAdjusted, 2);
        if ($assessment === null || $assessment->method === Assessment::DECLINED) {
            $class = $assessment === null ? self::NONE : self::DECLINED;
            return ['class' => $class, 'charge' => round($fees, 2), 'balance' => $balance, 'post' => 0.0, 'overpaid' => 0.0, 'pending' => false];
        }
        $class = $assessment->classLabel;
        if ($class !== Schedule::AT_OR_BELOW_100 && $class !== Schedule::NO_DISCOUNT && $schedule->classNamed($class) === null) {
            // A class no longer in the schedule: re-assess before discounting.
            return ['class' => self::NONE, 'charge' => round($fees, 2), 'balance' => $balance, 'post' => 0.0, 'overpaid' => 0.0, 'pending' => false];
        }
        $charge = $schedule->chargeFor($class, $fees);
        $pending = $insured && !$settled;
        $post = 0.0;
        if (!$pending) {
            $post = round(max(0.0, min($balance, $patientPaid + $balance - $charge)), 2);
        }
        return [
            'class' => $class,
            'charge' => $charge,
            'balance' => $balance,
            'post' => $post,
            'overpaid' => round(max(0.0, $patientPaid - $charge), 2),
            'pending' => $pending,
        ];
    }
}
