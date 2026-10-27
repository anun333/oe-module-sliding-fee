<?php

declare(strict_types=1);

namespace OpenHealthcare\SlidingFee\Tests;

use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\Common\Contract\Figures\Figure;
use OpenHealthcare\Common\Contract\Figures\FiguresRequest;
use OpenHealthcare\SlidingFee\Figures;
use OpenHealthcare\SlidingFee\Repository\EvaluationRepository;
use PHPUnit\Framework\TestCase;

if (!class_exists(QueryUtils::class)) {
    require_once __DIR__ . '/Stubs/QueryUtils.php';
}

/**
 * The figures another module's page asks for (openhealthcare-common's figures contract): the discounts posted over a
 * period and the visits with one, counted the evaluation's way.
 */
final class FiguresTest extends TestCase
{
    protected function tearDown(): void
    {
        QueryUtils::$results = [];
        QueryUtils::$calls = [];
    }

    private static function request(array $wanted = [], ?int $facilityId = null): FiguresRequest
    {
        return new FiguresRequest(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-09-30'), $wanted, $facilityId);
    }

    public function testTheDiscountsAndVisitsAreAnsweredWithWhereTheyComeFrom(): void
    {
        QueryUtils::$results = [[['visits' => 12, 'amount' => '1234.567']]];
        $request = self::request();
        Figures::answer($request, ['SFD', 'Sliding fee']);

        $figures = $request->figures();
        $this->assertSame([Figures::DISCOUNTS_POSTED, Figures::VISITS_DISCOUNTED], array_keys($figures));
        $discounts = $figures[Figures::DISCOUNTS_POSTED];
        $this->assertSame([1234.57, Figure::UNIT_MONEY, 'Sliding Fee Discount'], [$discounts->value, $discounts->unit, $discounts->module]);
        $this->assertSame([12, Figure::UNIT_COUNT], [$figures[Figures::VISITS_DISCOUNTED]->value, $figures[Figures::VISITS_DISCOUNTED]->unit]);
        $this->assertStringEndsWith('/public/evaluation.php?from=2026-01-01&to=2026-09-30', (string)$discounts->url);
        $this->assertSame('Patient adjustments with a sliding fee reason, on visits dated in the period', $discounts->note);
    }

    public function testTheTotalsCountDiscountsAsTheEvaluationDoes(): void
    {
        QueryUtils::$results = [[['visits' => 0, 'amount' => 0]]];
        (new EvaluationRepository())->discountTotals(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-09-30'), ['SFD'], 3);
        $call = QueryUtils::$calls[0];
        $this->assertStringContainsString('a.`payer_type` = 0 AND a.`memo` IN (?)', $call['sql'], 'patient-side adjustments with a reason, as visits() counts them');
        $this->assertStringContainsString('a.`deleted` IS NULL', $call['sql']);
        $this->assertStringContainsString('fe.`facility_id` = ?', $call['sql']);
        $this->assertSame(['SFD', '2026-01-01', '2026-10-01', 3], $call['binds'], 'the last day is included');
    }

    public function testWithNoReasonNothingCountsAndTheNoteSaysWhy(): void
    {
        QueryUtils::$results = [[['visits' => 0, 'amount' => 0]]];
        $request = self::request([Figures::DISCOUNTS_POSTED]);
        Figures::answer($request, []);
        $this->assertStringContainsString('AND 0 = 1 AND', QueryUtils::$calls[0]['sql']);
        $this->assertStringNotContainsString('facility_id', QueryUtils::$calls[0]['sql']);
        $this->assertSame([Figures::DISCOUNTS_POSTED], array_keys($request->figures()), 'only what was asked');
        $this->assertSame(0.0, $request->figures()[Figures::DISCOUNTS_POSTED]->value);
        $this->assertStringStartsWith('No Adjustment Reason', (string)$request->figures()[Figures::DISCOUNTS_POSTED]->note);
    }

    public function testOnlyARequestForThisModulesFiguresIsAnswered(): void
    {
        $this->assertTrue(Figures::asked(self::request()));
        $this->assertTrue(Figures::asked(self::request([Figures::VISITS_DISCOUNTED])));
        $this->assertFalse(Figures::asked(self::request(['caremgmt.months_billed'])));
    }
}
