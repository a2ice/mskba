<?php

namespace App\Modules\Pricing\Application\Services;

use App\Modules\Pricing\Domain\Models\PricingCategory;
use App\Modules\Pricing\Domain\Models\PricingPrice;
use App\Modules\Pricing\Domain\Models\PricingService;
use App\Modules\Pricing\Domain\Models\PricingVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PricingCatalogManager
{
    /** @param array<string, mixed> $attributes */
    public function saveCategory(array $attributes, ?PricingCategory $category = null): PricingCategory
    {
        $category ??= new PricingCategory;
        $category->fill($attributes);
        $category->save();

        return $category->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function saveService(array $attributes, ?PricingService $service = null): PricingService
    {
        $service ??= new PricingService;
        $service->fill($attributes);
        $service->save();

        return $service->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function saveVariant(array $attributes, ?PricingVariant $variant = null): PricingVariant
    {
        $variant ??= new PricingVariant;
        $variant->fill($attributes);
        $variant->save();

        return $variant->refresh();
    }

    public function setPrice(
        PricingService $service,
        ?PricingVariant $variant,
        int $amountMinor,
        string $currency = 'RUB',
    ): PricingPrice {
        if ($variant !== null && $variant->service_id !== $service->id) {
            throw ValidationException::withMessages([
                'variant_id' => 'Вариант не относится к выбранной услуге.',
            ]);
        }

        $currency = strtoupper($currency);
        $now = now();

        return DB::transaction(function () use ($service, $variant, $amountMinor, $currency, $now): PricingPrice {
            PricingPrice::query()
                ->where('service_id', $service->id)
                ->when(
                    $variant === null,
                    fn ($query) => $query->whereNull('variant_id'),
                    fn ($query) => $query->where('variant_id', $variant->id),
                )
                ->where('currency', $currency)
                ->where('is_active', true)
                ->lockForUpdate()
                ->get()
                ->each(function (PricingPrice $price) use ($now): void {
                    $price->update([
                        'is_active' => false,
                        'valid_until' => $now,
                    ]);
                });

            return PricingPrice::query()->create([
                'service_id' => $service->id,
                'variant_id' => $variant?->id,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'valid_from' => $now,
                'valid_until' => null,
                'is_active' => true,
            ]);
        });
    }

    public function deactivatePrice(PricingPrice $price): void
    {
        if (! $price->is_active) {
            return;
        }

        $price->update([
            'is_active' => false,
            'valid_until' => $price->valid_until ?? now(),
        ]);
    }

    public function deleteCategory(PricingCategory $category): void
    {
        DB::transaction(function () use ($category): void {
            $category->services()->get()->each(fn (PricingService $service) => $this->deleteService($service));
            $category->delete();
        });
    }

    public function deleteService(PricingService $service): void
    {
        DB::transaction(function () use ($service): void {
            $service->prices()
                ->where('is_active', true)
                ->get()
                ->each(fn (PricingPrice $price) => $this->deactivatePrice($price));

            $service->variants()->get()->each(fn (PricingVariant $variant) => $this->deleteVariant($variant));
            $service->delete();
        });
    }

    public function deleteVariant(PricingVariant $variant): void
    {
        DB::transaction(function () use ($variant): void {
            $variant->prices()
                ->where('is_active', true)
                ->get()
                ->each(fn (PricingPrice $price) => $this->deactivatePrice($price));

            $variant->delete();
        });
    }
}
