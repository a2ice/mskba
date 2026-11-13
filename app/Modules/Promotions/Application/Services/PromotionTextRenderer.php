<?php

namespace App\Modules\Promotions\Application\Services;

/**
 * Expand public promotion copy using the *resolved* reward amount, including
 * venue-specific overrides. Keep future tokens centralized here.
 */
final class PromotionTextRenderer
{
    public static function render(?string $template, ?int $amountMinor): ?string
    {
        if ($template === null) {
            return null;
        }

        if ($amountMinor === null) {
            return $template;
        }

        $rubles = $amountMinor / 100;
        $number = number_format($rubles, $amountMinor % 100 === 0 ? 0 : 2, ',', ' ');

        return strtr($template, [
            '{amount}' => $number.' ₽',
            '{amount_number}' => $number,
            '{currency}' => '₽',
        ]);
    }
}
