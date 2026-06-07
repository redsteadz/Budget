<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Bill;
use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\RecurringBudgetService;
use OCA\Budget\Service\RecurringIncomeService;
use PHPUnit\Framework\TestCase;

class RecurringBudgetServiceTest extends TestCase {
    private RecurringBudgetService $service;
    private BillService $billService;
    private RecurringIncomeService $recurringIncomeService;

    protected function setUp(): void {
        $this->billService = $this->createMock(BillService::class);
        $this->recurringIncomeService = $this->createMock(RecurringIncomeService::class);
        $this->service = new RecurringBudgetService($this->billService, $this->recurringIncomeService);
    }

    private function makeBill(float $amount, string $frequency, ?int $categoryId, ?array $split = null): Bill {
        $bill = new Bill();
        $bill->setAmount($amount);
        $bill->setFrequency($frequency);
        $bill->setCategoryId($categoryId);
        if ($split !== null) {
            $bill->setSplitTemplateArray($split);
        }
        return $bill;
    }

    private function makeIncome(float $amount, string $frequency, ?int $categoryId): RecurringIncome {
        $income = new RecurringIncome();
        $income->setAmount($amount);
        $income->setFrequency($frequency);
        $income->setCategoryId($categoryId);
        return $income;
    }

    public function testMonthlyBillSummedIntoCategory(): void {
        $this->billService->method('findActive')->willReturn([
            $this->makeBill(100.0, 'monthly', 5),
        ]);
        $this->recurringIncomeService->method('findActive')->willReturn([]);

        $result = $this->service->getMonthlyBudgetsByCategory('user1');

        $this->assertSame(100.0, $result[5]);
    }

    public function testYearlyAndWeeklyNormalizedToMonthly(): void {
        $this->billService->method('findActive')->willReturn([
            $this->makeBill(1200.0, 'yearly', 5),  // 1200/12 = 100/mo
            $this->makeBill(10.0, 'weekly', 6),    // 10 * 52/12 ≈ 43.33/mo
        ]);
        $this->recurringIncomeService->method('findActive')->willReturn([]);

        $result = $this->service->getMonthlyBudgetsByCategory('user1');

        $this->assertSame(100.0, $result[5]);
        $this->assertSame(round(10.0 * 52 / 12, 2), $result[6]);
    }

    public function testSplitBillDistributesToSplitCategories(): void {
        $bill = $this->makeBill(0.0, 'monthly', null, [
            ['categoryId' => 7, 'amount' => 30.0],
            ['categoryId' => 8, 'amount' => 20.0],
        ]);
        $this->billService->method('findActive')->willReturn([$bill]);
        $this->recurringIncomeService->method('findActive')->willReturn([]);

        $result = $this->service->getMonthlyBudgetsByCategory('user1');

        $this->assertSame(30.0, $result[7]);
        $this->assertSame(20.0, $result[8]);
    }

    public function testIncomeSummedAndCombinedWithBills(): void {
        $this->billService->method('findActive')->willReturn([
            $this->makeBill(50.0, 'monthly', 5),
        ]);
        $this->recurringIncomeService->method('findActive')->willReturn([
            $this->makeIncome(2000.0, 'monthly', 9),
            $this->makeIncome(50.0, 'monthly', 5), // same category as the bill -> combined
        ]);

        $result = $this->service->getMonthlyBudgetsByCategory('user1');

        $this->assertSame(100.0, $result[5]);
        $this->assertSame(2000.0, $result[9]);
    }

    public function testBillWithoutCategoryIgnored(): void {
        $this->billService->method('findActive')->willReturn([
            $this->makeBill(100.0, 'monthly', null),
        ]);
        $this->recurringIncomeService->method('findActive')->willReturn([]);

        $result = $this->service->getMonthlyBudgetsByCategory('user1');

        $this->assertSame([], $result);
    }
}
