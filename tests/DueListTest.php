<?php

/**
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\SlidingFee\Tests;

use OpenHealthcare\SlidingFee\Domain\DueList;
use OpenHealthcare\SlidingFee\Domain\Reassessment;
use PHPUnit\Framework\TestCase;

final class DueListTest extends TestCase
{
    /** 300 never assessed, 120 ran out, 30 runs out soon: 450 in all. */
    private static function due(): array
    {
        $rows = [];
        foreach ([Reassessment::NEVER => 300, Reassessment::EXPIRED => 120, Reassessment::SOON => 30] as $status => $n) {
            for ($i = 0; $i < $n; $i++) {
                $rows[] = ['pid' => count($rows) + 1, 'status' => $status];
            }
        }
        return $rows;
    }

    public function testTheListIsShownTwoHundredAtATimeWithTheTotal(): void
    {
        $first = DueList::page(self::due(), 1);
        self::assertCount(200, $first['rows']);
        self::assertSame([450, 3, 1, 200], [$first['total'], $first['pages'], $first['first'], $first['last']]);
        self::assertSame(1, $first['rows'][0]['pid']);

        $last = DueList::page(self::due(), 3);
        self::assertCount(50, $last['rows']);
        self::assertSame([401, 450], [$last['first'], $last['last']]);
        self::assertSame(401, $last['rows'][0]['pid']);
    }

    public function testAPageOutsideTheListShowsTheNearestPage(): void
    {
        self::assertSame(3, DueList::page(self::due(), 99)['page']);
        self::assertSame(1, DueList::page(self::due(), 0)['page']);
        self::assertSame(1, DueList::page(self::due(), -4)['page']);
    }

    public function testTheStatusFilterPagesOnlyThatStatusAndKeepsEveryStatussCount(): void
    {
        $ranOut = DueList::page(self::due(), 1, Reassessment::EXPIRED);
        self::assertSame([120, 1, 120], [$ranOut['total'], $ranOut['pages'], count($ranOut['rows'])]);
        self::assertSame([Reassessment::EXPIRED], array_values(array_unique(array_column($ranOut['rows'], 'status'))));
        $counts = [Reassessment::NEVER => 300, Reassessment::EXPIRED => 120, Reassessment::SOON => 30];
        self::assertSame($counts, $ranOut['counts']);
        self::assertSame($counts, DueList::page(self::due(), 1)['counts']);

        // An unknown status is no filter at all.
        $unknown = DueList::page(self::due(), 1, "x' OR 1=1");
        self::assertSame(['', 450], [$unknown['status'], $unknown['total']]);
    }

    public function testAnEmptyListIsOnePageWithNothingShown(): void
    {
        $empty = DueList::page([], 5);
        self::assertSame([[], 0, 1, 1, 0, 0], [$empty['rows'], $empty['total'], $empty['page'], $empty['pages'], $empty['first'], $empty['last']]);
    }

    public function testTheAuditEntrySummarisesTheListAndNamesOnlyThePatientsShown(): void
    {
        $page = DueList::page(self::due(), 2, Reassessment::NEVER);
        $shown = array_column($page['rows'], 'pid');
        $message = DueList::auditMessage($page['total'], $page['status'], $page['page'], $page['pages'], $shown);
        self::assertSame('Sliding Fee Discount: re-assessments due viewed, 300 patients due, status never, page 2 of 2, 100 shown, pids: '
            . implode(',', range(201, 300)), $message);
        self::assertStringNotContainsString(',200,', $message . ',');

        $everyone = DueList::page(self::due(), 1);
        self::assertStringStartsWith('Sliding Fee Discount: re-assessments due viewed, 450 patients due, page 1 of 3, 200 shown, pids: 1,2,',
            DueList::auditMessage($everyone['total'], $everyone['status'], $everyone['page'], $everyone['pages'], array_column($everyone['rows'], 'pid')));
    }
}
