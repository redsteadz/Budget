<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

/**
 * Computes the predictable monthly amount each category is committed to through
 * active recurring bills and recurring income (#269).
 *
 * The budget view uses these figures as an automatic fallback: a (sub-)category
 * with no manually-set budget shows its committed recurring total as the limit,
 * so the "fixed" part of a budget is derived from the recurring items while the
 * "variable" part can still be typed in manually (which always wins).
 *
 * Amounts are normalized to a monthly figure regardless of the item's
 * frequency; the frontend converts that to the category's budget period.
 */
class RecurringBudgetService {
    private BillService $billService;
    private RecurringIncomeService $recurringIncomeService;

    public function __construct(
        BillService $billService,
        RecurringIncomeService $recurringIncomeService
    ) {
        $this->billService = $billService;
        $this->recurringIncomeService = $recurringIncomeService;
    }

    /**
     * Monthly-normalized recurring total per category id.
     *
     * Bills are summed into their category (split-template bills distribute to
     * each split's category); recurring income is summed into its category.
     *
     * @param string $userId
     * @return array<int, float> categoryId => monthly amount
     */
    public function getMonthlyBudgetsByCategory(string $userId): array {
        $totals = [];

        foreach ($this->billService->findActive($userId) as $bill) {
            $factor = $this->monthlyFactor($bill->getFrequency());
            $splits = $bill->getSplitTemplateArray();
            if (!empty($splits)) {
                foreach ($splits as $split) {
                    $catId = isset($split['categoryId']) ? (int)$split['categoryId'] : 0;
                    $amount = isset($split['amount']) ? (float)$split['amount'] : 0.0;
                    if ($catId > 0 && $amount != 0.0) {
                        $totals[$catId] = ($totals[$catId] ?? 0.0) + $amount * $factor;
                    }
                }
            } elseif ($bill->getCategoryId()) {
                $catId = (int)$bill->getCategoryId();
                $totals[$catId] = ($totals[$catId] ?? 0.0) + (float)$bill->getAmount() * $factor;
            }
        }

        foreach ($this->recurringIncomeService->findActive($userId) as $income) {
            if (!$income->getCategoryId()) {
                continue;
            }
            $catId = (int)$income->getCategoryId();
            $factor = $this->monthlyFactor($income->getFrequency());
            $totals[$catId] = ($totals[$catId] ?? 0.0) + (float)$income->getAmount() * $factor;
        }

        foreach ($totals as $id => $amount) {
            $totals[$id] = round($amount, 2);
        }

        return $totals;
    }

    /**
     * Factor converting an amount at the given frequency into a monthly amount.
     */
    private function monthlyFactor(string $frequency): float {
        switch ($frequency) {
            case 'weekly':
                return 52.0 / 12.0;
            case 'biweekly':
                return 26.0 / 12.0;
            case 'quarterly':
                return 1.0 / 3.0;
            case 'yearly':
                return 1.0 / 12.0;
            case 'monthly':
            case 'custom':
            default:
                return 1.0;
        }
    }
}
