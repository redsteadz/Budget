<?php

declare(strict_types=1);

namespace OCA\Budget\Dashboard;

use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\AmountFormatter;
use OCA\Budget\Service\BudgetAlertService;
use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * Nextcloud dashboard widget: total balance and this month's budget status.
 * Server-rendered via IAPIWidgetV2 — no app JS bundle required.
 */
class BudgetOverviewWidget implements IAPIWidget, IAPIWidgetV2, IIconWidget, IButtonWidget {

    public function __construct(
        private AccountService $accountService,
        private BudgetAlertService $budgetAlertService,
        private AmountFormatter $amountFormatter,
        private IL10N $l,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getId(): string {
        return 'budget-overview';
    }

    public function getTitle(): string {
        return $this->l->t('Budget: Overview');
    }

    public function getOrder(): int {
        return 21;
    }

    public function getIconClass(): string {
        return 'icon-budget-widget';
    }

    public function getIconUrl(): string {
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->imagePath('budget', 'app-dark.svg')
        );
    }

    public function getUrl(): ?string {
        return $this->urlGenerator->linkToRouteAbsolute('budget.page.index') . '#/dashboard';
    }

    public function load(): void {
        Util::addStyle('budget', 'dashboard');
    }

    /**
     * @return WidgetItem[]
     */
    public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
        return $this->buildItems($userId);
    }

    public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
        $items = $this->buildItems($userId);
        return new WidgetItems(
            $items,
            empty($items) ? $this->l->t('No accounts yet') : ''
        );
    }

    /**
     * @return WidgetButton[]
     */
    public function getWidgetButtons(string $userId): array {
        return [
            new WidgetButton(
                WidgetButton::TYPE_MORE,
                $this->getUrl(),
                $this->l->t('Open Budget')
            ),
        ];
    }

    /**
     * @return WidgetItem[]
     */
    private function buildItems(string $userId): array {
        $items = [];

        $accountSummary = $this->accountService->getSummary($userId);
        if (($accountSummary['accountCount'] ?? 0) === 0) {
            return [];
        }

        $items[] = new WidgetItem(
            $this->l->t('Total balance'),
            $this->amountFormatter->formatForUser($userId, (float) ($accountSummary['totalBalance'] ?? 0)),
            $this->getUrl(),
            $this->getIconUrl(),
            'balance'
        );

        $budget = $this->budgetAlertService->getSummary($userId);
        if (($budget['totalCategories'] ?? 0) > 0) {
            $items[] = new WidgetItem(
                $this->l->t('Budget this month'),
                $this->l->t('%1$s of %2$s spent (%3$s%%)', [
                    $this->amountFormatter->formatForUser($userId, (float) $budget['totalSpent']),
                    $this->amountFormatter->formatForUser($userId, (float) $budget['totalBudget']),
                    (string) $budget['overallPercentage'],
                ]),
                $this->getUrl(),
                $this->getIconUrl(),
                'budget'
            );

            $overCount = (int) ($budget['overBudgetCount'] ?? 0);
            $warningCount = (int) ($budget['warningCount'] ?? 0);
            if ($overCount > 0 || $warningCount > 0) {
                $parts = [];
                if ($overCount > 0) {
                    $parts[] = $this->l->n('%n category over budget', '%n categories over budget', $overCount);
                }
                if ($warningCount > 0) {
                    $parts[] = $this->l->n('%n warning', '%n warnings', $warningCount);
                }
                $items[] = new WidgetItem(
                    $this->l->t('Attention'),
                    implode(', ', $parts),
                    $this->urlGenerator->linkToRouteAbsolute('budget.page.index') . '#/budget',
                    $this->getIconUrl(),
                    'alerts'
                );
            }
        }

        return $items;
    }
}
