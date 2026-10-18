<?php

/**
 * Patients' income assessments. Recording one also updates Demographics (family size,
 * monthly income and Financial Review Date) through OpenEMR's own updatePatientData(),
 * so UDS Table 4 and everything else that reads those fields sees the same income.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\SlidingFee\Repository;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Core\OEGlobalsBag;
use OpenHealthcare\SlidingFee\Domain\Assessment;

final class AssessmentRepository
{
    /** @return list<Assessment> newest first */
    public function forPatient(int $pid): array
    {
        return $this->forPatients([$pid])[$pid] ?? [];
    }

    /**
     * @param list<int> $pids
     * @return array<int, list<Assessment>> newest first
     */
    public function forPatients(array $pids): array
    {
        if ($pids === []) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($pids), '?'));
        $rows = QueryUtils::fetchRecords(
            "SELECT `id`, `pid`, `assessed_on`, `method`, `annual_income`, `family_size`, `percent`, `class_label`
               FROM `sliding_fee_assessment` WHERE `pid` IN ($in) ORDER BY `assessed_on` DESC, `id` DESC",
            $pids,
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['pid']][] = new Assessment(
                (int)$r['id'],
                (int)$r['pid'],
                new \DateTimeImmutable((string)$r['assessed_on']),
                (string)$r['method'],
                $r['annual_income'] === null ? null : (float)$r['annual_income'],
                $r['family_size'] === null ? null : (int)$r['family_size'],
                $r['percent'] === null ? null : (float)$r['percent'],
                (string)$r['class_label'],
            );
        }
        return $out;
    }

    /** @return array<int, array{note: string, user: string, created: string}> by assessment id */
    public function details(int $pid): array
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT a.`id`, a.`note`, a.`created_at`, CONCAT_WS(' ', u.`fname`, u.`lname`) AS user_name
               FROM `sliding_fee_assessment` AS a LEFT JOIN `users` AS u ON u.`id` = a.`user_id`
              WHERE a.`pid` = ?",
            [$pid],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['id']] = ['note' => (string)($r['note'] ?? ''), 'user' => trim((string)($r['user_name'] ?? '')), 'created' => (string)$r['created_at']];
        }
        return $out;
    }

    public function add(Assessment $a, ?int $scheduleId, string $note, int $userId): int
    {
        $id = (int)QueryUtils::sqlInsert(
            'INSERT INTO `sliding_fee_assessment` (`pid`, `assessed_on`, `method`, `annual_income`, `family_size`, `percent`, `class_label`, `schedule_id`, `note`, `user_id`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$a->pid, $a->assessedOn->format('Y-m-d'), $a->method, $a->annualIncome, $a->familySize,
                $a->percent === null ? null : round($a->percent, 2), $a->classLabel, $scheduleId, $note, $userId],
        );
        if ($a->method !== Assessment::DECLINED && $a->annualIncome !== null && $a->familySize !== null) {
            require_once OEGlobalsBag::getInstance()->getSrcDir() . '/patient.inc.php';
            updatePatientData($a->pid, [
                'family_size' => (string)$a->familySize,
                'monthly_income' => number_format($a->annualIncome / 12, 2, '.', ''),
                'financial_review' => $a->assessedOn->format('Y-m-d') . ' 00:00:00',
            ]);
        }
        return $id;
    }
}
