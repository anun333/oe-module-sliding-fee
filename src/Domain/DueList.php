<?php

/**
 * One page of the re-assessments due list. At clinic scale the list runs to thousands of patients
 * (about 15,500 on a 20,000-patient test site, 6.5 MB when every row was sent at once), so the page
 * shows 200 at a time, optionally of one status, with each status's count over the whole list.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\SlidingFee\Domain;

final class DueList
{
    public const PER_PAGE = 200;
    public const STATUSES = [Reassessment::NEVER, Reassessment::EXPIRED, Reassessment::SOON];

    /**
     * @template T of array{pid: int, status: string}
     * @param list<T> $due   the whole list, in Reassessment::due()'s order
     * @param string  $status one of STATUSES, or '' for every status; anything else counts as ''
     * @return array{rows: list<T>, total: int, page: int, pages: int, first: int, last: int, status: string, counts: array<string, int>}
     *         total and pages are of the filtered list; counts are each status's count over the whole list; first and
     *         last are the 1-based positions shown (0 when nothing is)
     */
    public static function page(array $due, int $page, string $status = '', int $perPage = self::PER_PAGE): array
    {
        $perPage = max(1, $perPage);
        $status = in_array($status, self::STATUSES, true) ? $status : '';
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($due as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']]++;
            }
        }
        $filtered = $status === '' ? $due : array_values(array_filter($due, static fn(array $r): bool => $r['status'] === $status));
        $total = count($filtered);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $rows = array_slice($filtered, ($page - 1) * $perPage, $perPage);
        $first = $rows === [] ? 0 : ($page - 1) * $perPage + 1;
        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'first' => $first,
            'last' => $rows === [] ? 0 : $first + count($rows) - 1,
            'status' => $status,
            'counts' => $counts,
        ];
    }

    /**
     * The audit entry for a page of the list: how many are due, the filter and page, and the patients shown on this
     * page, rather than every patient on the list (thousands of ids in one entry at clinic scale).
     *
     * @param list<int> $shown the pids of the rows on this page
     */
    public static function auditMessage(int $total, string $status, int $page, int $pages, array $shown): string
    {
        return 'Sliding Fee Discount: re-assessments due viewed, ' . $total . ' patients due'
            . ($status === '' ? '' : ', status ' . $status)
            . ', page ' . $page . ' of ' . $pages . ', ' . count($shown) . ' shown, pids: ' . implode(',', $shown);
    }
}
