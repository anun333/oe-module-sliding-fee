<?php

declare(strict_types=1);

namespace OpenHealthcare\SlidingFee\Tests;

use OpenHealthcare\SlidingFee\Domain\Assessment;
use OpenHealthcare\SlidingFee\Domain\Evaluation;
use OpenHealthcare\SlidingFee\Domain\NoticeTable;
use OpenHealthcare\SlidingFee\Domain\PovertyGuidelines;
use OpenHealthcare\SlidingFee\Domain\Reassessment;
use OpenHealthcare\SlidingFee\Domain\Schedule;
use OpenHealthcare\SlidingFee\Domain\ScheduleCheck;
use OpenHealthcare\SlidingFee\Domain\VisitDiscount;
use PHPUnit\Framework\TestCase;

final class DomainTest extends TestCase
{
    private const TODAY = '2026-10-18';

    public function testTheGuidelinesAreTheHhsFiguresByRegionAndHouseholdSize(): void
    {
        $g = self::guidelines();
        $this->assertSame(15960, $g->guideline(2026, 'contiguous', 1));
        $this->assertSame(33000, $g->guideline(2026, 'contiguous', 4), '15,960 + 3 x 5,680');
        $this->assertSame(19950 + 7100, $g->guideline(2026, 'alaska', 2));
        $this->assertSame(18360, $g->guideline(2026, 'hawaii', 1));
        $this->assertNull($g->guideline(2025, 'alaska', 1), 'a row still to be filled in is not loaded');
        $this->assertNull($g->guideline(2026, 'contiguous', 0));
        $this->assertSame(100.0, $g->percent(33000, 4, 2026, 'contiguous'));
        $this->assertSame(2026, $g->latestYear('contiguous'));
        $this->assertStringContainsString('Federal Register', (string)$g->source(2026, 'contiguous'));
    }

    public function testPayClassBoundariesAreInclusiveAtTheirTop(): void
    {
        $s = self::schedule();
        $this->assertSame(Schedule::AT_OR_BELOW_100, $s->classFor(100.0));
        $this->assertSame('A', $s->classFor(100.01));
        $this->assertSame('A', $s->classFor(125.0));
        $this->assertSame('B', $s->classFor(125.01));
        $this->assertSame('D', $s->classFor(200.0));
        $this->assertSame(Schedule::NO_DISCOUNT, $s->classFor(200.01));
    }

    public function testChargesByMethod(): void
    {
        $s = self::schedule();
        $this->assertSame(0.0, $s->chargeFor(Schedule::AT_OR_BELOW_100, 180.0), 'full discount');
        $this->assertSame(45.0, $s->chargeFor('A', 180.0), '75% off');
        $this->assertSame(90.0, $s->chargeFor('B', 180.0), '50% off');
        $this->assertSame(180.0, $s->chargeFor(Schedule::NO_DISCOUNT, 180.0));
        $nominal = self::schedule(atOrBelow100: Schedule::NOMINAL, nominal: 20);
        $this->assertSame(20.0, $nominal->chargeFor(Schedule::AT_OR_BELOW_100, 180.0));
        $this->assertSame(15.0, $nominal->chargeFor(Schedule::AT_OR_BELOW_100, 15.0), 'never more than the fees');
        $flat = self::schedule(classes: [self::c('A', 133, 'flat', 30), self::c('B', 166, 'flat', 50), self::c('C', 200, 'flat', 70)]);
        $this->assertSame(30.0, $flat->chargeFor('A', 180.0));
        $this->assertSame(25.0, $flat->chargeFor('A', 25.0));
    }

    public function testACompliantScheduleHasNoErrors(): void
    {
        $findings = ScheduleCheck::run(self::schedule(), self::guidelines(), [150.0, 90.0], new \DateTimeImmutable(self::TODAY));
        $this->assertSame([], array_values(array_filter($findings, static fn(array $f): bool => $f['level'] === ScheduleCheck::ERROR)));
        $this->assertSame([], $findings, 'nor warnings');
    }

    /** @return iterable<string, array{Schedule, string}> */
    public static function broken(): iterable
    {
        yield 'two classes in 101-200' => [self::schedule(classes: [self::c('A', 150, 'percent', 75), self::c('B', 200, 'percent', 50)]), 'HRSA requires at least three'];
        yield 'stops short of 200' => [self::schedule(classes: [self::c('A', 125, 'percent', 75), self::c('B', 150, 'percent', 50), self::c('C', 175, 'percent', 25)]), 'would get no discount'];
        yield 'above 200 unfunded' => [self::schedule(classes: [...self::classes(), self::c('E', 250, 'percent', 10)]), 'other funding'];
        yield 'discount grows' => [self::schedule(classes: [self::c('A', 125, 'percent', 50), self::c('B', 150, 'percent', 75), self::c('C', 175, 'percent', 25), self::c('D', 200, 'percent', 10)]), 'must shrink'];
        yield 'flat charge falls' => [self::schedule(classes: [self::c('A', 133, 'flat', 40), self::c('B', 166, 'flat', 30), self::c('C', 200, 'flat', 50)]), 'must shrink'];
        yield 'class at 100' => [self::schedule(classes: [self::c('Z', 100, 'percent', 90), ...self::classes()]), 'at or below 100%'];
        yield 'percent over 100' => [self::schedule(classes: [self::c('A', 125, 'percent', 120), self::c('B', 150, 'percent', 50), self::c('C', 175, 'percent', 25), self::c('D', 200, 'percent', 10)]), 'between 0 and 100'];
        yield 'nominal not below flat class' => [self::schedule(atOrBelow100: Schedule::NOMINAL, nominal: 30, classes: [self::c('A', 133, 'flat', 30), self::c('B', 166, 'flat', 50), self::c('C', 200, 'flat', 70)]), 'must be less than'];
        yield 'guidelines not loaded' => [self::schedule(region: 'alaska', year: 2025), 'not loaded'];
        yield 'same name twice' => [self::schedule(classes: [self::c('A', 125, 'percent', 75), self::c('A', 150, 'percent', 50), self::c('C', 175, 'percent', 25), self::c('D', 200, 'percent', 10)]), 'same name'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('broken')]
    public function testEachBrokenScheduleIsAnError(Schedule $s, string $expected): void
    {
        $errors = array_column(array_filter(ScheduleCheck::run($s, self::guidelines(), [150.0], new \DateTimeImmutable(self::TODAY)), static fn(array $f): bool => $f['level'] === ScheduleCheck::ERROR), 'message');
        $this->assertNotSame([], array_filter($errors, static fn(string $m): bool => str_contains($m, $expected)), implode(' | ', $errors));
    }

    public function testWarningsForWhatNeedsCheckingByHand(): void
    {
        $today = new \DateTimeImmutable(self::TODAY);
        $messages = static fn(Schedule $s, array $fees = [150.0]): string => implode(' | ', array_column(array_filter(
            ScheduleCheck::run($s, self::guidelines(), $fees, $today),
            static fn(array $f): bool => $f['level'] === ScheduleCheck::WARNING,
        ), 'message'));

        $this->assertStringContainsString('the 2026 guidelines are loaded', $messages(self::schedule(year: 2025)));
        $this->assertStringContainsString('No Adjustment Reason', $messages(self::schedule(reasons: [])));
        $this->assertStringContainsString('Record when the board approved', $messages(self::schedule(approvedOn: null)));
        $this->assertStringContainsString('due for its evaluation', $messages(self::schedule(approvedOn: '2023-10-17')));
        $this->assertStringNotContainsString('due for its evaluation', $messages(self::schedule(approvedOn: '2023-10-19')));
        $this->assertStringContainsString('same discount', $messages(self::schedule(classes: [self::c('A', 133, 'percent', 50), self::c('B', 166, 'percent', 50), self::c('C', 200, 'percent', 50)])));
        $this->assertStringContainsString('different methods', $messages(self::schedule(classes: [self::c('A', 133, 'percent', 75), self::c('B', 166, 'flat', 50), self::c('C', 200, 'flat', 70)])));
        // A nominal $20 against a first class that takes 75% off: fees of $80 or less are a problem.
        $nominal = self::schedule(atOrBelow100: Schedule::NOMINAL, nominal: 20);
        $this->assertStringContainsString('For a fee of $80.00 or less', $messages($nominal, [150.0, 80.0, 45.0]));
        $this->assertStringContainsString('2 of the fees', $messages($nominal, [150.0, 80.0, 45.0]));
        $this->assertStringNotContainsString('For a fee of', $messages($nominal, [150.0, 80.01]));
    }

    public function testTheVisitDiscountForAnUninsuredPatient(): void
    {
        $s = self::schedule();
        $a = self::assessment('B');
        $first = VisitDiscount::compute($s, $a, 180, 0, 0, 0, 0, 0, false, true);
        $this->assertSame(['class' => 'B', 'charge' => 90.0, 'balance' => 180.0, 'post' => 90.0, 'overpaid' => 0.0, 'pending' => false], $first);
        $after = VisitDiscount::compute($s, $a, 180, 0, 0, 0, 0, 90, false, true);
        $this->assertSame(0.0, $after['post'], 'once posted, nothing more');
        $paidAtCheckout = VisitDiscount::compute($s, $a, 180, 0, 0, 90, 0, 0, false, true);
        $this->assertSame(90.0, $paidAtCheckout['post'], 'the patient paid the charge; the rest is the discount');
    }

    public function testHrsasOwnExampleForAnInsuredPatient(): void
    {
        // Chapter 9, footnote 9: an $80 fee, a $60 copay, a class that pays half the fee: the patient pays $40.
        $s = self::schedule(classes: [self::c('A', 125, 'percent', 75), self::c('B', 150, 'percent', 50), self::c('C', 175, 'percent', 25), self::c('D', 200, 'percent', 10)]);
        $result = VisitDiscount::compute($s, self::assessment('B'), 80, 20, 0, 0, 0, 0, true, true);
        $this->assertSame(40.0, $result['charge']);
        $this->assertSame(60.0, $result['balance']);
        $this->assertSame(20.0, $result['post']);

        $pending = VisitDiscount::compute($s, self::assessment('B'), 80, 0, 0, 0, 0, 0, true, false);
        $this->assertTrue($pending['pending']);
        $this->assertSame(0.0, $pending['post'], 'not until insurance has finished');
        $this->assertSame(40.0, $pending['charge'], 'but the most the patient pays is known');

        $overpaid = VisitDiscount::compute($s, self::assessment('B'), 80, 20, 0, 60, 0, 0, true, true);
        $this->assertSame([0.0, 20.0], [$overpaid['post'], $overpaid['overpaid']], 'paid the whole copay: $20 over the charge');
    }

    public function testNoAssessmentOrADeclinedOneGetsNoDiscount(): void
    {
        $s = self::schedule();
        $this->assertSame(VisitDiscount::NONE, VisitDiscount::compute($s, null, 100, 0, 0, 0, 0, 0, false, true)['class']);
        $declined = new Assessment(1, 7, new \DateTimeImmutable('2026-10-01'), Assessment::DECLINED, null, null, null, Schedule::NO_DISCOUNT);
        $result = VisitDiscount::compute($s, $declined, 100, 0, 0, 0, 0, 0, false, true);
        $this->assertSame([VisitDiscount::DECLINED, 0.0], [$result['class'], $result['post']]);
        $this->assertSame(VisitDiscount::NONE, VisitDiscount::compute($s, self::assessment('Gone'), 100, 0, 0, 0, 0, 0, false, true)['class'], 'a class no longer in the schedule');
    }

    public function testTheAssessmentInForceAndItsExpiry(): void
    {
        $older = new Assessment(1, 7, new \DateTimeImmutable('2025-10-18'), Assessment::DOCUMENTS, 20000, 2, 92.0, Schedule::AT_OR_BELOW_100);
        $newer = new Assessment(2, 7, new \DateTimeImmutable('2026-03-01'), Assessment::SELF_DECLARED, 30000, 2, 138.0, 'B');
        $this->assertSame(1, Assessment::inForce([$older, $newer], new \DateTimeImmutable('2026-02-28'), 12)?->id);
        $this->assertSame(2, Assessment::inForce([$older, $newer], new \DateTimeImmutable('2026-03-01'), 12)?->id);
        $this->assertNull(Assessment::inForce([$older], new \DateTimeImmutable('2026-10-18'), 12), 'expired after 12 months');
        $this->assertSame(1, Assessment::inForce([$older], new \DateTimeImmutable('2026-10-17'), 12)?->id);
        $this->assertNull(Assessment::inForce([$newer], new \DateTimeImmutable('2026-02-01'), 12), 'not before it was made');
    }

    public function testAnAssessmentIsPlacedOnTheScheduleInUse(): void
    {
        // $33,000 for a household of 4 is exactly 100% of the 2026 guideline.
        $a = new Assessment(1, 7, new \DateTimeImmutable('2026-02-01'), Assessment::DOCUMENTS, 33000, 4, 105.4, 'A');
        $placed = $a->on(self::schedule(), self::guidelines());
        $this->assertSame(100.0, $placed->percent);
        $this->assertSame(Schedule::AT_OR_BELOW_100, $placed->classLabel);
        $declined = new Assessment(2, 7, new \DateTimeImmutable('2026-02-01'), Assessment::DECLINED, null, null, null, Schedule::NO_DISCOUNT);
        $this->assertSame($declined, $declined->on(self::schedule(), self::guidelines()));
    }

    public function testTheScheduleSurvivesTheFormAndItsStorage(): void
    {
        $s = self::schedule();
        $this->assertEquals($s, Schedule::fromArray($s->toArray()));
        $posted = Schedule::fromArray(['region' => 'nowhere', 'year' => '2026', 'atOrBelow100' => 'nominal', 'nominal' => '10',
            'classes' => [['label' => ' B ', 'upTo' => '150', 'method' => 'flat', 'value' => '25'], ['label' => '', 'upTo' => '120'], ['label' => 'A', 'upTo' => '125', 'value' => 'x']],
            'reasons' => ['', ' Sliding fee discount '], 'approvedOn' => '18/10/2026']);
        $this->assertSame('contiguous', $posted->region);
        $this->assertSame(['A', 'B'], array_column($posted->classes, 'label'), 'sorted by their top, blank ones dropped');
        $this->assertSame([0.0, 25.0], array_column($posted->classes, 'value'));
        $this->assertSame(['Sliding fee discount'], $posted->reasons);
        $this->assertNull($posted->approvedOn, 'only a YYYY-MM-DD date');
    }

    public function testUtilizationIsGroupedByThePayClassInForceOnEachVisit(): void
    {
        $s = self::schedule();
        $g = self::guidelines();
        $assessments = [
            1 => [new Assessment(1, 1, new \DateTimeImmutable('2026-01-10'), Assessment::DOCUMENTS, 15000, 1, null, 'A')],  // 94%: at or below 100
            2 => [new Assessment(2, 2, new \DateTimeImmutable('2026-01-10'), Assessment::SELF_DECLARED, 30000, 2, null, 'A')], // 138%: B
            3 => [new Assessment(3, 3, new \DateTimeImmutable('2026-01-10'), Assessment::DECLINED, null, null, null, Schedule::NO_DISCOUNT)],
            4 => [new Assessment(4, 4, new \DateTimeImmutable('2024-01-10'), Assessment::DOCUMENTS, 15000, 1, null, 'A')],  // expired
        ];
        $visit = static fn(int $pid, string $date, float $fees, float $sliding): array => ['pid' => $pid, 'encounter' => $pid * 10, 'date' => new \DateTimeImmutable($date), 'fees' => $fees, 'sliding' => $sliding];
        $rows = Evaluation::byClass($s, $g, [
            $visit(1, '2026-02-01', 100, 100), $visit(1, '2026-03-01', 50, 50),
            $visit(2, '2026-02-01', 120, 60),
            $visit(3, '2026-02-01', 80, 0),
            $visit(4, '2026-02-01', 90, 0),
            $visit(5, '2026-02-01', 70, 0),
        ], $assessments);

        $this->assertSame([Schedule::AT_OR_BELOW_100, 'A', 'B', 'C', 'D', Schedule::NO_DISCOUNT, VisitDiscount::DECLINED, VisitDiscount::NONE], array_keys($rows));
        $this->assertSame(['patients' => 1, 'visits' => 2, 'fees' => 150.0, 'sliding' => 150.0], $rows[Schedule::AT_OR_BELOW_100]);
        $this->assertSame(['patients' => 1, 'visits' => 1, 'fees' => 120.0, 'sliding' => 60.0], $rows['B'], 'placed by the income on the schedule in use, not the class stored');
        $this->assertSame(1, $rows[VisitDiscount::DECLINED]['visits']);
        $this->assertSame(['patients' => 2, 'visits' => 2, 'fees' => 160.0, 'sliding' => 0.0], $rows[VisitDiscount::NONE], 'an expired assessment and none at all');
        $this->assertSame(0, $rows['A']['visits']);
    }

    public function testTheNoticeTableGivesEachHouseholdSizeItsFullAndPartialDiscountLimits(): void
    {
        // 2026, 48 contiguous states: $15,960 for one person and $5,680 for each more; the top class ends at 200%.
        $rows = NoticeTable::rows(self::schedule(), self::guidelines());
        $this->assertNotNull($rows);
        $this->assertCount(8, $rows);
        $this->assertSame(['1', '$15,960', '$31,920'], $rows[0]);
        $this->assertSame(['4', '$33,000', '$66,000'], $rows[3]);
        $this->assertSame(['8', '$55,720', '$111,440'], $rows[7]);
    }

    public function testThePartialLimitIsTheHighestClassAndRoundsDown(): void
    {
        $rows = NoticeTable::rows(self::schedule(classes: [self::c('A', 133.3, 'percent', 50), self::c('B', 187.5, 'percent', 25)]), self::guidelines());
        $this->assertSame('$29,925', $rows[0][2] ?? null, '187.5% of $15,960 is $29,925.00');
        $this->assertSame('$40,575', $rows[1][2] ?? null, '187.5% of $21,640 is $40,575.00');
    }

    public function testWithoutItsGuidelinesOrAnyClassTheNoticeStaysBlank(): void
    {
        $this->assertNull(NoticeTable::rows(self::schedule(year: 2019), self::guidelines()), 'no 2019 guidelines on file');
        $this->assertNull(NoticeTable::rows(self::schedule(classes: []), self::guidelines()), 'no pay classes, so no partial discount to state');
        $this->assertStringContainsString('2026', NoticeTable::note(self::schedule(), 'en'));
        $this->assertStringStartsWith('Según', NoticeTable::note(self::schedule(), 'es'));
    }

    public function testTitleXChargesNothingAtOrBelow100EvenWithANominalChargeEntered(): void
    {
        $hrsa = self::schedule(atOrBelow100: Schedule::NOMINAL, nominal: 10);
        $titleX = self::titleX(atOrBelow100: Schedule::NOMINAL, nominal: 10);
        $this->assertSame(10.0, $hrsa->chargeFor(Schedule::AT_OR_BELOW_100, 50));
        $this->assertSame(0.0, $titleX->chargeFor(Schedule::AT_OR_BELOW_100, 50), '42 CFR 59.5(a)(7): no charge for a low-income family');
    }

    public function testTitleXSchedulesAreCheckedAgainstTitleXNotHrsa(): void
    {
        $today = new \DateTimeImmutable(self::TODAY);
        $errors = static fn(Schedule $s): array => array_column(array_filter(ScheduleCheck::run($s, self::guidelines(), [150.0], $today), static fn(array $f): bool => $f['level'] === ScheduleCheck::ERROR), 'message');
        $warnings = static fn(Schedule $s): array => array_column(array_filter(ScheduleCheck::run($s, self::guidelines(), [150.0], $today), static fn(array $f): bool => $f['level'] === ScheduleCheck::WARNING), 'message');

        // Discounts to 250%, two classes up to 200%: compliant for Title X, though HRSA would want three to 200% and nothing above it.
        $good = self::titleX();
        $this->assertSame([], $errors($good));
        $this->assertSame([], array_values(array_filter($warnings($good), static fn(string $w): bool => str_contains($w, 'board'))), 'Title X asks nothing of a board');
        $this->assertNotSame([], $errors(self::schedule(classes: self::titleXClasses())), 'the same classes on HRSA rules are errors');

        $this->assertStringContainsString('59.5(a)(7)', implode(' ', $errors(self::titleX(atOrBelow100: Schedule::NOMINAL, nominal: 5))));
        $this->assertStringContainsString('250%', implode(' ', $errors(self::titleX(classes: self::classes()))), 'classes that stop at 200% leave 201% to 250% without a discount');
        $this->assertStringContainsString('above 250%', implode(' ', $warnings(self::titleX(classes: [...self::titleXClasses(), self::c('D', 300, 'percent', 10)]))));
    }

    public function testThePatientADirectorCountsAsLowIncomeIsAtOrBelow100OnTitleXOnly(): void
    {
        $director = new Assessment(1, 7, new \DateTimeImmutable('2026-02-01'), Assessment::DOCUMENTS, 100000, 2, null, Schedule::NO_DISCOUNT, Assessment::DIRECTOR);
        $family = new Assessment(2, 7, new \DateTimeImmutable('2026-02-01'), Assessment::DOCUMENTS, 100000, 2, null, Schedule::NO_DISCOUNT);
        $onTitleX = $director->on(self::titleX(), self::guidelines());
        $this->assertSame(Schedule::AT_OR_BELOW_100, $onTitleX->classLabel, '42 CFR 59.2: unable, for good reasons, to pay');
        $this->assertGreaterThan(250, $onTitleX->percent, 'the income and percentage are kept as recorded');
        $this->assertSame(Assessment::DIRECTOR, $onTitleX->basis);
        $this->assertSame(Schedule::NO_DISCOUNT, $director->on(self::schedule(), self::guidelines())->classLabel, 'an HRSA schedule places them by income');
        $this->assertSame(Schedule::NO_DISCOUNT, $family->on(self::titleX(), self::guidelines())->classLabel);
    }

    public function testAnInsuredTitleXClientPaysNoMoreThanTheirDiscountedCharge(): void
    {
        // 42 CFR 59.5(a)(8)(ii): at or below 250%, copays and fees no more than the schedule of discounts charges.
        // $100 fee, insurance paid $30, class A takes 75% off: the patient pays $25, not the $70 left.
        $s = self::titleX();
        $client = new Assessment(1, 7, new \DateTimeImmutable('2026-10-01'), Assessment::DOCUMENTS, 21000, 1, 140.0, 'A');
        $result = VisitDiscount::compute($s, $client, 100, 30, 0, 0, 0, 0, true, true);
        $this->assertSame([25.0, 70.0, 45.0], [$result['charge'], $result['balance'], $result['post']]);
        // Class C takes 25% off, so pays $75: more than the $70 left, so nothing is owed as a discount.
        $c = new Assessment(2, 7, new \DateTimeImmutable('2026-10-01'), Assessment::DOCUMENTS, 36000, 1, 225.0, 'C');
        $this->assertSame(0.0, VisitDiscount::compute($s, $c, 100, 30, 0, 0, 0, 0, true, true)['post']);
    }

    public function testTheRulesSurviveStorageAndAServiceSchedule(): void
    {
        $titleX = self::titleX();
        $this->assertSame(Schedule::TITLE_X, Schedule::fromArray($titleX->toArray())->rules);
        $this->assertSame(Schedule::HRSA, Schedule::fromArray(['rules' => 'something else'])->rules, 'anything else is HRSA');
        $this->assertSame(Schedule::HRSA, Schedule::fromArray([])->rules, 'a schedule saved before 0.4.0 is HRSA');
        $familyPlanning = Schedule::fromArray($titleX->toArray() + [])->withProgram(self::schedule());
        $this->assertTrue($familyPlanning->isTitleX(), 'a service schedule keeps its own rules');
    }

    public function testOnTitleXRulesTheVisitsContraceptiveProductsAreDiscounted(): void
    {
        $products = [
            ['drugId' => 5, 'fee' => 30.0, 'contraceptive' => true],
            ['drugId' => 6, 'fee' => 20.0, 'contraceptive' => false],
            ['drugId' => 7, 'fee' => 10.0, 'contraceptive' => false],
        ];
        $titleX = Schedule::fromArray(['contraceptiveProducts' => [7]] + self::titleX()->toArray());
        $this->assertTrue($titleX->discountsProduct(5, true), 'a product with a CYP factor counts');
        $this->assertTrue($titleX->discountsProduct(7, false), 'a product the schedule lists counts');
        $this->assertFalse($titleX->discountsProduct(6, false), 'another product does not');
        $this->assertSame(40.0, $titleX->discountedProductFees($products));

        $hrsa = self::schedule();
        $this->assertFalse($hrsa->discountsProduct(5, true), 'HRSA rules discount no products');
        $this->assertSame(0.0, $hrsa->discountedProductFees($products));

        // A $100 visit with those products: at or below 100% the client pays nothing, products included;
        // in class A (75% off) they pay a quarter of the $140, not of the $100.
        $fees = 100.0 + $titleX->discountedProductFees($products);
        $low = new Assessment(1, 7, new \DateTimeImmutable('2026-10-01'), Assessment::DOCUMENTS, 10000, 1, 60.0, Schedule::AT_OR_BELOW_100);
        $result = VisitDiscount::compute($titleX, $low, $fees, 0, 0, 0, 0, 0, false, true);
        $this->assertSame([0.0, 140.0], [$result['charge'], $result['post']]);
        $a = new Assessment(2, 7, new \DateTimeImmutable('2026-10-01'), Assessment::DOCUMENTS, 21000, 1, 140.0, 'A');
        $this->assertSame(35.0, VisitDiscount::compute($titleX, $a, $fees, 0, 0, 0, 0, 0, false, true)['charge']);
    }

    public function testTheContraceptiveProductsSurviveStorageOnlyOnTitleXRules(): void
    {
        $titleX = Schedule::fromArray(['contraceptiveProducts' => [9, '3', 'x', 0, 3]] + self::titleX()->toArray());
        $this->assertSame([3, 9], $titleX->contraceptiveProducts, 'ids only, sorted, no duplicates');
        $this->assertSame([3, 9], Schedule::fromArray($titleX->toArray())->contraceptiveProducts);
        $main = self::schedule();
        $this->assertSame([3, 9], $titleX->withProgram($main)->contraceptiveProducts, 'a service schedule keeps its own list');
        $this->assertSame([], Schedule::fromArray(['contraceptiveProducts' => [9]] + $main->toArray())->contraceptiveProducts, 'HRSA rules keep none');
    }

    private static function guidelines(): PovertyGuidelines
    {
        return PovertyGuidelines::fromFile(__DIR__ . '/../data/poverty-guidelines.json');
    }

    /** @return array{label: string, upTo: float, method: string, value: float} */
    private static function c(string $label, float $upTo, string $method, float $value): array
    {
        return ['label' => $label, 'upTo' => $upTo, 'method' => $method, 'value' => $value];
    }

    /** @return list<array{label: string, upTo: float, method: string, value: float}> */
    private static function classes(): array
    {
        return [self::c('A', 125, 'percent', 75), self::c('B', 150, 'percent', 50), self::c('C', 175, 'percent', 25), self::c('D', 200, 'percent', 10)];
    }

    /**
     * @param list<array{label: string, upTo: float, method: string, value: float}>|null $classes
     * @param list<string>                                                               $reasons
     */
    public function testAServiceScheduleCoversItsVisitCategoriesAndTakesTheProgramFromTheMainOne(): void
    {
        $main = self::schedule();
        $dental = Schedule::fromArray([
            'region' => 'alaska', 'year' => 2025, 'reassessMonths' => 6, 'atOrBelow100' => 'nominal', 'nominal' => 10,
            'classes' => [self::c('A', 150, 'flat', 20), self::c('B', 175, 'flat', 30), self::c('C', 200, 'flat', 40)],
            'service' => 'dental', 'name' => 'Dental', 'categories' => ['14', 12, 0, 'x', 14],
        ])->withProgram($main);
        $this->assertSame([12, 14], $dental->categories, 'kept as numbers, once each, in order');
        $this->assertSame(['contiguous', 2026, 12], [$dental->region, $dental->year, $dental->reassessMonths], 'the program settings are the main schedule\'s');
        $this->assertSame(10.0, $dental->nominal, 'its own nominal charge: one per schedule');
        $this->assertSame($dental->toArray(), Schedule::fromArray($dental->toArray())->toArray(), 'it survives its storage');
        $this->assertSame($dental, Schedule::forCategory([$main, $dental], 14));
        $this->assertSame($main, Schedule::forCategory([$main, $dental], 9), 'any other category uses the main schedule');
        $this->assertSame($main, Schedule::forCategory([$main, $dental], 0), 'and so does a visit with no category');
        $this->assertNull(Schedule::forCategory([$dental], 9), 'no main schedule, no schedule for other visits');
        $this->assertSame([], Schedule::fromArray(['categories' => [5]])->categories, 'the main schedule covers everything, so it names no categories');
        $this->assertSame('main', Schedule::fromArray(['service' => 'Not a key!'])->service);
        $this->assertSame('dental-clinic', Schedule::serviceKey(' Dental clinic '));
        $this->assertNotSame(Schedule::MAIN, Schedule::serviceKey('Main'));
    }

    public function testAVisitIsPlacedOnItsOwnSchedulesClasses(): void
    {
        $main = self::schedule();
        $dental = new Schedule('contiguous', 2026, Schedule::FULL, 0, [self::c('Low', 150, 'percent', 60), self::c('Mid', 175, 'percent', 40), self::c('High', 200, 'percent', 20)],
            12, ['Sliding fee discount'], false, null, 'dental', 'Dental', [14]);
        $a = new Assessment(1, 7, new \DateTimeImmutable('2026-02-01'), Assessment::DOCUMENTS, 30000, 2, null, 'B');
        $placed = $a->on(Schedule::forCategory([$main, $dental], 14) ?? $main, self::guidelines());
        $this->assertSame('Low', $placed->classLabel, '139% of the 2-person guideline ($21,640) is B on the main schedule but Low on Dental');
        $this->assertSame('B', $a->on($main, self::guidelines())->classLabel);
        $result = VisitDiscount::compute($dental, $placed, 100.0, 0, 0, 0, 0, 0, false, true);
        $this->assertSame(40.0, $result['charge'], '60% off on the dental schedule');
    }

    public function testReassessmentsDueAreThoseNeverAssessedRunOutOrRunningOutSoon(): void
    {
        $today = new \DateTimeImmutable(self::TODAY);
        $visit = new \DateTimeImmutable('2026-09-01');
        $at = static fn(int $pid, string $on): Assessment => new Assessment($pid, $pid, new \DateTimeImmutable($on), Assessment::DOCUMENTS, 20000, 2, 92.0, Schedule::AT_OR_BELOW_100);
        $due = Reassessment::due(
            [1 => $visit, 2 => $visit, 3 => $visit, 4 => $visit, 5 => $visit],
            [
                2 => [$at(2, '2025-09-01'), $at(2, '2025-10-01')],  // ran out 2026-10-01
                3 => [$at(3, '2025-11-05')],                        // runs out 2026-11-05, within 30 days
                4 => [$at(4, '2026-03-01')],                        // good until 2027-03-01
                5 => [$at(5, '2027-01-01')],                        // dated in the future: not in force yet
            ],
            $today, 12, 30,
        );
        $this->assertSame([[1, Reassessment::NEVER], [5, Reassessment::NEVER], [2, Reassessment::EXPIRED], [3, Reassessment::SOON]],
            array_map(static fn(array $r): array => [$r['pid'], $r['status']], $due), 'never assessed first, then by due date; 4 is not due');
        $this->assertSame('2026-10-01', $due[2]['dueOn']?->format('Y-m-d'), 'from the latest assessment, not the first');
        $this->assertSame([], Reassessment::due([], [], $today, 12, 30));
    }

    private static function schedule(
        string $region = 'contiguous',
        int $year = 2026,
        string $atOrBelow100 = Schedule::FULL,
        float $nominal = 0,
        ?array $classes = null,
        array $reasons = ['Sliding fee discount'],
        ?string $approvedOn = '2026-01-15',
    ): Schedule {
        return new Schedule($region, $year, $atOrBelow100, $nominal, $classes ?? self::classes(), 12, $reasons, false, $approvedOn);
    }

    /** @param list<array{label: string, upTo: float, method: string, value: float}>|null $classes */
    private static function titleX(string $atOrBelow100 = Schedule::FULL, float $nominal = 0, ?array $classes = null): Schedule
    {
        return new Schedule('contiguous', 2026, $atOrBelow100, $nominal, $classes ?? self::titleXClasses(), 12, ['Sliding fee discount'], false, null,
            Schedule::MAIN, '', [], Schedule::TITLE_X);
    }

    /** @return list<array{label: string, upTo: float, method: string, value: float}> */
    private static function titleXClasses(): array
    {
        return [self::c('A', 150, 'percent', 75), self::c('B', 200, 'percent', 50), self::c('C', 250, 'percent', 25)];
    }

    private static function assessment(string $class): Assessment
    {
        return new Assessment(1, 7, new \DateTimeImmutable('2026-10-01'), Assessment::DOCUMENTS, 30000, 2, 138.0, $class);
    }
}
