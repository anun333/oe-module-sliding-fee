<?php

declare(strict_types=1);

namespace OpenHealthcare\SlidingFee\Tests;

use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\SlidingFee\Repository\EvaluationRepository;
use OpenHealthcare\SlidingFee\Repository\VisitRepository;
use PHPUnit\Framework\TestCase;

if (!class_exists(QueryUtils::class)) {
    require_once __DIR__ . '/Stubs/QueryUtils.php';
}

/**
 * Which adjustments the visit and evaluation queries count as sliding fee discounts. The sums themselves run in
 * the database; these pin the conditions they're asked with.
 */
final class RepositoryTest extends TestCase
{
    protected function tearDown(): void
    {
        QueryUtils::$results = [];
        QueryUtils::$calls = [];
    }

    public function testADiscountIsAPatientAdjustmentWithOneOfTheReasons(): void
    {
        $this->assertSame(['`payer_type` = 0 AND `memo` IN (?, ?)', ['Sliding fee', 'SFD']], VisitRepository::slidingRow(['Sliding fee', 'SFD']));
        $this->assertSame(['a.`payer_type` = 0 AND a.`memo` IN (?)', ['SFD']], VisitRepository::slidingRow(['SFD'], 'a'));
    }

    public function testWithNoReasonSetNoAdjustmentIsADiscount(): void
    {
        $this->assertSame(['0 = 1', []], VisitRepository::slidingRow([]));
    }

    /** @return list<list<array<string, mixed>>> */
    private static function visitRows(): array
    {
        return [
            [['id' => 1, 'date' => '2026-10-01 09:00:00', 'category' => 5, 'closed' => 0]],
            [['fees' => '100.00', 'copays' => '0']],
            [['ins_paid' => '0', 'ins_adj' => '0', 'pt_paid' => '0', 'pt_adj' => '0', 'sliding' => '0']],
            [['n' => 0]],
        ];
    }

    public function testAVisitWithNoReasonSetCountsEveryPatientAdjustmentAsAnAdjustmentAndNoneAsADiscount(): void
    {
        QueryUtils::$results = self::visitRows();
        (new VisitRepository())->visit(7, 501, []);
        $sql = QueryUtils::$calls[2]['sql'];
        $this->assertStringNotContainsString("IN ('')", $sql, 'an empty memo is not a reason');
        $this->assertStringContainsString('WHEN 0 = 1 THEN `adj_amount`', $sql, 'nothing is a discount');
        $this->assertStringContainsString('`payer_type` = 0 AND NOT (0 = 1)', $sql, "every patient adjustment is the patient's");
        $this->assertSame([7, 501], QueryUtils::$calls[2]['binds']);
    }

    public function testAnInsurersAdjustmentIsNeverADiscount(): void
    {
        QueryUtils::$results = self::visitRows();
        (new VisitRepository())->visit(7, 501, ['SFD']);
        $sql = QueryUtils::$calls[2]['sql'];
        $this->assertStringContainsString('WHEN `payer_type` = 0 AND `memo` IN (?) THEN `adj_amount`', $sql, 'the discount is the patient side only');
        $this->assertSame(['SFD', 'SFD', 7, 501], QueryUtils::$calls[2]['binds']);
    }

    public function testTheEvaluationCountsDiscountsTheSameWay(): void
    {
        QueryUtils::$results = [[]];
        (new EvaluationRepository())->visits(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'), []);
        $this->assertStringNotContainsString("IN ('')", QueryUtils::$calls[0]['sql']);
        $this->assertStringContainsString('AND 0 = 1) AS sliding', QueryUtils::$calls[0]['sql']);
        $this->assertSame(['2026-01-01', '2027-01-01'], QueryUtils::$calls[0]['binds']);

        QueryUtils::$results = [[]];
        (new EvaluationRepository())->visits(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'), ['SFD']);
        $this->assertStringContainsString('a.`payer_type` = 0 AND a.`memo` IN (?)', QueryUtils::$calls[1]['sql']);
    }

    public function testAVisitsProductsAreItsSalesWithCoresContraceptiveMark(): void
    {
        QueryUtils::$results = [[
            ['drug_id' => 5, 'name' => 'Oral contraceptive', 'fee' => '30.00', 'cyp' => '0.0667'],
            ['drug_id' => 6, 'name' => 'Vitamins', 'fee' => '20.00', 'cyp' => '0'],
        ]];
        $products = (new VisitRepository())->products(7, 501);
        $this->assertSame([
            ['drugId' => 5, 'name' => 'Oral contraceptive', 'fee' => 30.0, 'contraceptive' => true],
            ['drugId' => 6, 'name' => 'Vitamins', 'fee' => 20.0, 'contraceptive' => false],
        ], $products);
        $this->assertStringContainsString('`trans_type` = 1', QueryUtils::$calls[0]['sql'], 'sales only, not purchases, returns or transfers');
        $this->assertSame([7, 501], QueryUtils::$calls[0]['binds']);
    }

    public function testTheEvaluationsProductsAreKeyedByVisit(): void
    {
        QueryUtils::$results = [[
            ['pid' => 7, 'encounter' => 501, 'drug_id' => 5, 'fee' => '30.00', 'cyp' => '1'],
            ['pid' => 7, 'encounter' => 501, 'drug_id' => 6, 'fee' => '20.00', 'cyp' => '0'],
            ['pid' => 8, 'encounter' => 9, 'drug_id' => 5, 'fee' => '15.00', 'cyp' => '1'],
        ]];
        $byVisit = (new EvaluationRepository())->productsByVisit(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        $this->assertSame(['7:501', '8:9'], array_keys($byVisit));
        $this->assertCount(2, $byVisit['7:501']);
        $this->assertTrue($byVisit['8:9'][0]['contraceptive']);
        $this->assertStringContainsString('s.`trans_type` = 1', QueryUtils::$calls[0]['sql']);
        $this->assertSame(['2026-01-01', '2027-01-01'], QueryUtils::$calls[0]['binds']);
    }
}
