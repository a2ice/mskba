<?php

namespace App\Modules\Pricing\Application\Services;

use App\Modules\Pricing\Domain\Models\PricingPrice;
use App\Modules\Pricing\Domain\Models\PricingService;
use Carbon\CarbonInterface;

final class PricingPriceResolver
{
    public function resolve(
        string $serviceCode,
        ?string $variantCode = null,
        ?CarbonInterface $at = null,
        string $currency = 'RUB',
    ): ?PricingPrice {
        $at ??= now();

        $service = PricingService::query()
            ->where('code', $serviceCode)
            ->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->first();

        if ($service === null) {
            return null;
        }

        $variantId = null;

        if ($variantCode !== null) {
            $variant = $service->variants()
                ->where('code', $variantCode)
                ->where('is_active', true)
                ->first();

            if ($variant === null) {
                return null;
            }

            $variantId = $variant->id;
        }

        return PricingPrice::query()
            ->where('service_id', $service->id)
            ->when(
                $variantId === null,
                fn ($query) => $query->whereNull('variant_id'),
                fn ($query) => $query->where('variant_id', $variantId),
            )
            ->where('currency', strtoupper($currency))
            ->where('is_active', true)
            ->where('valid_from', '<=', $at)
            ->where(function ($query) use ($at): void {
                $query->whereNull('valid_until')->orWhere('valid_until', '>', $at);
            })
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->first();
    }
}
