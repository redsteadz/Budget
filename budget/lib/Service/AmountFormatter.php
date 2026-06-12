<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

/**
 * Formats monetary amounts with the user's currency symbol for server-rendered
 * surfaces (notifications, dashboard widgets, calendar feeds). The frontend
 * has its own richer formatter; this stays deliberately simple.
 */
class AmountFormatter {

    private const SYMBOLS = [
        'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥',
        'CAD' => 'CA$', 'AUD' => 'A$', 'CHF' => 'CHF ', 'CNY' => '¥',
    ];

    public function __construct(
        private SettingService $settingService,
    ) {
    }

    /**
     * Format an amount with the given currency symbol (falls back to the
     * currency code as prefix for currencies without a common symbol).
     */
    public function format(float $amount, string $currency): string {
        $symbol = self::SYMBOLS[$currency] ?? $currency . ' ';
        return $symbol . number_format($amount, 2);
    }

    /**
     * Format using the user's configured default currency.
     */
    public function formatForUser(string $userId, float $amount, ?string $currency = null): string {
        if ($currency === null) {
            try {
                $currency = $this->settingService->get($userId, 'currency') ?? 'USD';
            } catch (\Exception $e) {
                $currency = 'USD';
            }
        }
        return $this->format($amount, $currency);
    }
}
