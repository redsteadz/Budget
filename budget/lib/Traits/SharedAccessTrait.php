<?php

declare(strict_types=1);

namespace OCA\Budget\Traits;

use OCA\Budget\Exception\ReadOnlyShareException;
use OCA\Budget\Service\GranularShareService;

/**
 * Trait for controllers that support granular budget sharing.
 *
 * Provides convenience methods to get visible entity IDs and check
 * write permissions. Controllers use these instead of raw userId scoping.
 *
 * Controllers using this trait must:
 * 1. Have a `$userId` property (the authenticated Nextcloud user)
 * 2. Inject GranularShareService and call `setGranularShareService()` in constructor
 */
trait SharedAccessTrait {
    protected ?GranularShareService $granularShareService = null;

    protected function setGranularShareService(GranularShareService $service): void {
        $this->granularShareService = $service;
    }

    /**
     * Returns the authenticated user's own ID.
     * Kept for backward compatibility — no longer swaps to another user.
     */
    protected function getEffectiveUserId(): string {
        return $this->userId;
    }

    /** @return int[] */
    protected function getVisibleAccountIds(): array {
        return $this->granularShareService->getVisibleAccountIds($this->userId);
    }

    /**
     * Resolve a report's account scope from a legacy single accountId and an
     * optional multi-select accountIds array (#299). Returns
     * [effectiveAccountId, visibleAccountIds] to pass to a report service:
     *  - multi-select: scope is the selected accounts intersected with the ones
     *    the user can actually see; the single accountId is cleared.
     *  - single accountId: unchanged.
     *  - neither: all visible accounts.
     *
     * @param int[]|null $accountIds
     * @return array{0: ?int, 1: int[]}
     */
    protected function resolveAccountScope(?int $accountId, ?array $accountIds): array {
        $visible = $this->getVisibleAccountIds();
        if (!empty($accountIds)) {
            $selected = array_values(array_intersect(
                array_map('intval', $accountIds),
                $visible
            ));
            // Fall back to all visible accounts if the selection resolves to
            // nothing accessible, rather than scoping to an empty set.
            return [null, $selected !== [] ? $selected : $visible];
        }
        return [$accountId, $visible];
    }

    /** @return int[] */
    protected function getVisibleCategoryIds(): array {
        return $this->granularShareService->getVisibleCategoryIds($this->userId);
    }

    /** @return int[] */
    protected function getVisibleBillIds(): array {
        return $this->granularShareService->getVisibleBillIds($this->userId);
    }

    /** @return int[] */
    protected function getVisibleRecurringIncomeIds(): array {
        return $this->granularShareService->getVisibleRecurringIncomeIds($this->userId);
    }

    /** @return int[] */
    protected function getVisibleSavingsGoalIds(): array {
        return $this->granularShareService->getVisibleSavingsGoalIds($this->userId);
    }

    /**
     * Check write permission. Throws ReadOnlyShareException if denied.
     */
    protected function requireWriteAccess(string $entityType, int $entityId): void {
        $this->granularShareService->requireWriteAccess($this->userId, $entityType, $entityId);
    }

    /**
     * Check if user can access a specific entity.
     */
    protected function canAccessEntity(string $entityType, int $entityId): bool {
        return $this->granularShareService->canAccess($this->userId, $entityType, $entityId);
    }
}
