<?php

/**
 * Who is due for a sliding fee re-assessment: patients seen recently whose assessment in
 * force runs out within the window, has run out, or who were never assessed. The center
 * sets how often to re-assess (Compliance Manual Chapter 9, element g); the module's
 * schedule holds that period.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\SlidingFee\Domain;

final class Reassessment
{
    public const NEVER = 'never';
    public const EXPIRED = 'expired';
    public const SOON = 'soon';

    /**
     * @param array<int, \DateTimeImmutable> $lastVisits      pid => the patient's latest visit in the period looked at
     * @param array<int, list<Assessment>>   $assessments     pid => that patient's assessments
     * @return list<array{pid: int, status: string, assessedOn: ?\DateTimeImmutable, dueOn: ?\DateTimeImmutable, lastVisit: \DateTimeImmutable}>
     *         NEVER first, then by due date
     */
    public static function due(array $lastVisits, array $assessments, \DateTimeImmutable $today, int $months, int $windowDays): array
    {
        $soon = $today->modify("+$windowDays days");
        $out = [];
        foreach ($lastVisits as $pid => $lastVisit) {
            $latest = null;
            foreach ($assessments[$pid] ?? [] as $a) {
                if ($a->assessedOn <= $today && ($latest === null || $a->assessedOn > $latest->assessedOn)) {
                    $latest = $a;
                }
            }
            if ($latest === null) {
                $out[] = ['pid' => $pid, 'status' => self::NEVER, 'assessedOn' => null, 'dueOn' => null, 'lastVisit' => $lastVisit];
                continue;
            }
            $dueOn = $latest->expiresOn($months);
            if ($dueOn <= $today) {
                $out[] = ['pid' => $pid, 'status' => self::EXPIRED, 'assessedOn' => $latest->assessedOn, 'dueOn' => $dueOn, 'lastVisit' => $lastVisit];
            } elseif ($dueOn <= $soon) {
                $out[] = ['pid' => $pid, 'status' => self::SOON, 'assessedOn' => $latest->assessedOn, 'dueOn' => $dueOn, 'lastVisit' => $lastVisit];
            }
        }
        usort($out, static fn(array $a, array $b): int => [$a['dueOn'] === null ? 0 : 1, $a['dueOn'], $a['pid']] <=> [$b['dueOn'] === null ? 0 : 1, $b['dueOn'], $b['pid']]);
        return $out;
    }
}
