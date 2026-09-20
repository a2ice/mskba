<?php

namespace App\Modules\Pricing\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Pricing\Application\Services\PricingCatalogManager;
use App\Modules\Pricing\Domain\Models\PricingCategory;
use App\Modules\Pricing\Domain\Models\PricingPrice;
use App\Modules\Pricing\Domain\Models\PricingService;
use App\Modules\Pricing\Domain\Models\PricingVariant;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class AdminPricingController extends Controller
{
    public function index(): Response
    {
        $categories = PricingCategory::query()
            ->with(['services.variants'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $currentPrices = PricingPrice::query()
            ->current()
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (PricingPrice $price): string => $this->priceTargetKey($price->service_id, $price->variant_id))
            ->keyBy(fn (PricingPrice $price): string => $this->priceTargetKey($price->service_id, $price->variant_id));

        $priceHistory = PricingPrice::query()
            ->with(['service', 'variant'])
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return ThemeResolver::page('admin.pricing', [
            'categories' => $categories,
            'currentPrices' => $currentPrices,
            'priceHistory' => $priceHistory,
        ]);
    }

    public function storeCategory(Request $request, PricingCatalogManager $manager): RedirectResponse
    {
        $manager->saveCategory($this->validateCategory($request));

        return back()->with('success', 'Категория прайса создана.');
    }

    public function updateCategory(
        Request $request,
        PricingCategory $category,
        PricingCatalogManager $manager,
    ): RedirectResponse {
        $manager->saveCategory($this->validateCategory($request, $category), $category);

        return back()->with('success', 'Категория прайса сохранена.');
    }

    public function destroyCategory(PricingCategory $category, PricingCatalogManager $manager): RedirectResponse
    {
        $manager->deleteCategory($category);

        return back()->with('success', 'Категория и её элементы удалены из активного каталога.');
    }

    public function storeService(Request $request, PricingCatalogManager $manager): RedirectResponse
    {
        $manager->saveService($this->validateService($request));

        return back()->with('success', 'Услуга прайса создана.');
    }

    public function updateService(
        Request $request,
        PricingService $service,
        PricingCatalogManager $manager,
    ): RedirectResponse {
        $manager->saveService($this->validateService($request, $service), $service);

        return back()->with('success', 'Услуга прайса сохранена.');
    }

    public function destroyService(PricingService $service, PricingCatalogManager $manager): RedirectResponse
    {
        $manager->deleteService($service);

        return back()->with('success', 'Услуга удалена из активного каталога.');
    }

    public function storeVariant(Request $request, PricingCatalogManager $manager): RedirectResponse
    {
        $manager->saveVariant($this->validateVariant($request));

        return back()->with('success', 'Вариант услуги создан.');
    }

    public function updateVariant(
        Request $request,
        PricingVariant $variant,
        PricingCatalogManager $manager,
    ): RedirectResponse {
        $manager->saveVariant($this->validateVariant($request, $variant), $variant);

        return back()->with('success', 'Вариант услуги сохранён.');
    }

    public function destroyVariant(PricingVariant $variant, PricingCatalogManager $manager): RedirectResponse
    {
        $manager->deleteVariant($variant);

        return back()->with('success', 'Вариант удалён из активного каталога.');
    }

    public function storePrice(Request $request, PricingCatalogManager $manager): RedirectResponse
    {
        $validated = $request->validate([
            'service_id' => [
                'required',
                'integer',
                Rule::exists('pricing_services', 'id')->whereNull('deleted_at'),
            ],
            'variant_id' => [
                'nullable',
                'integer',
                Rule::exists('pricing_variants', 'id')->whereNull('deleted_at'),
            ],
            'amount_rub' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
        ]);

        $service = PricingService::query()->findOrFail((int) $validated['service_id']);
        $variant = isset($validated['variant_id'])
            ? PricingVariant::query()->findOrFail((int) $validated['variant_id'])
            : null;

        $manager->setPrice(
            service: $service,
            variant: $variant,
            amountMinor: (int) round(((float) $validated['amount_rub']) * 100),
        );

        return back()->with('success', 'Новая цена установлена. Предыдущая версия сохранена в истории.');
    }

    public function deactivatePrice(PricingPrice $price, PricingCatalogManager $manager): RedirectResponse
    {
        $manager->deactivatePrice($price);

        return back()->with('success', 'Цена деактивирована.');
    }

    /** @return array<string, mixed> */
    private function validateCategory(Request $request, ?PricingCategory $category = null): array
    {
        return $request->validate([
            'code' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9][a-z0-9_]*$/',
                Rule::unique('pricing_categories', 'code')->ignore($category?->id),
            ],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function validateService(Request $request, ?PricingService $service = null): array
    {
        return $request->validate([
            'category_id' => [
                'required',
                'integer',
                Rule::exists('pricing_categories', 'id')->whereNull('deleted_at'),
            ],
            'code' => [
                'required',
                'string',
                'max:96',
                'regex:/^[a-z0-9][a-z0-9_]*$/',
                Rule::unique('pricing_services', 'code')->ignore($service?->id),
            ],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function validateVariant(Request $request, ?PricingVariant $variant = null): array
    {
        $serviceId = (int) $request->input('service_id');

        return $request->validate([
            'service_id' => [
                'required',
                'integer',
                Rule::exists('pricing_services', 'id')->whereNull('deleted_at'),
            ],
            'code' => [
                'required',
                'string',
                'max:96',
                'regex:/^[a-z0-9][a-z0-9_]*$/',
                Rule::unique('pricing_variants', 'code')
                    ->where(fn ($query) => $query->where('service_id', $serviceId))
                    ->ignore($variant?->id),
            ],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    private function priceTargetKey(int $serviceId, ?int $variantId): string
    {
        return $serviceId.':'.($variantId ?? 'base');
    }
}
