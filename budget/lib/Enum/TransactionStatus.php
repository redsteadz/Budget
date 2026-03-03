<?php

declare(strict_types=1);

namespace OCA\Budget\Enum;

/**
 * Transaction status enum for tracking whether a transaction has occurred.
 */
enum TransactionStatus: string {
    case CLEARED = 'cleared';
    case SCHEDULED = 'scheduled';

    /**
     * Get human-readable label.
     */
    public function label(): string {
        return match ($this) {
            self::CLEARED => 'Cleared',
            self::SCHEDULED => 'Scheduled',
        };
    }

    /**
     * Check if this transaction should be included in reports.
     * Scheduled transactions with future dates should be excluded.
     */
    public function isReportable(): bool {
        return $this === self::CLEARED;
    }

    /**
     * Get all valid transaction status values as strings.
     */
    public static function values(): array {
        return array_map(fn(self $s) => $s->value, self::cases());
    }

    /**
     * Check if a string is a valid transaction status.
     */
    public static function isValid(string $value): bool {
        return in_array($value, self::values(), true);
    }
}
