<?php

namespace Tests\Feature\Pricing;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Pricing\Application\Services\PricingPriceResolver;
use App\Modules\Pricing\Domain\Models\PricingCategory;
use App\Modules\Pricing\Domain\Models\PricingPrice;
use App\Modules\Pricing\Domain\Models\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_ai_generation_catalog_contains_three_100_ruble_services(): void
    {
        $this->assertDatabaseHas('pricing_categories', [
            'code' => 'ai_generation',
            'is_active' => true,
        ]);

        $services = PricingService::query()
            ->whereIn('code', ['avatar_generation', 'team_generation', 'game_highlights_generation'])
            ->get();

        $this->assertCount(3, $services);

        foreach ($services as $service) {
            $price = app(PricingPriceResolver::class)->resolve($service->code);

            $this->assertNotNull($price);
            $this->assertSame(10000, $price->amount_minor);
            $this->assertSame('RUB', $price->currency);
        }
    }

    public function test_admin_can_replace_current_price_without_rewriting_history(): void
    {
        $admin = $this->admin();
        $service = PricingService::query()->where('code', 'avatar_generation')->firstOrFail();
        $oldPrice = app(PricingPriceResolver::class)->resolve('avatar_generation');

        $this->assertNotNull($oldPrice);

        $this
            ->actingAs($admin)
            ->post(route('admin.pricing.prices.store'), [
                'service_id' => $service->id,
                'variant_id' => null,
                'amount_rub' => '149.90',
            ])
            ->assertRedirect();

        $oldPrice->refresh();
        $newPrice = app(PricingPriceResolver::class)->resolve('avatar_generation');

        $this->assertFalse($oldPrice->is_active);
        $this->assertNotNull($oldPrice->valid_until);
        $this->assertNotNull($newPrice);
        $this->assertSame(14990, $newPrice->amount_minor);
        $this->assertNotSame($oldPrice->id, $newPrice->id);
        $this->assertSame(2, PricingPrice::query()->where('service_id', $service->id)->count());
    }

    public function test_inactive_category_makes_service_unavailable_to_price_resolver(): void
    {
        $category = PricingCategory::query()->where('code', 'ai_generation')->firstOrFail();
        $category->update(['is_active' => false]);

        $this->assertNull(app(PricingPriceResolver::class)->resolve('avatar_generation'));
    }

    public function test_admin_catalog_changes_are_written_to_standard_audit_log(): void
    {
        config()->set('audit.ignore_console', false);

        $admin = $this->admin();

        $this
            ->actingAs($admin)
            ->post(route('admin.pricing.categories.store'), [
                'code' => 'test_category',
                'name' => 'Тестовая категория',
                'description' => null,
                'sort_order' => 99,
                'is_active' => '1',
            ])
            ->assertRedirect();

        $category = PricingCategory::query()->where('code', 'test_category')->firstOrFail();

        $audit = AuditLog::query()
            ->where('auditable_type', PricingCategory::class)
            ->where('auditable_id', $category->id)
            ->where('event', 'created')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('test_category', $audit->new_values['code']);
        $this->assertNotNull($audit->actor_id);
    }

    public function test_admin_pricing_page_shows_seeded_catalog(): void
    {
        $this
            ->actingAs($this->admin())
            ->get(route('admin.pricing.index'))
            ->assertOk()
            ->assertSee('Прайс')
            ->assertSee('Генерация аватара')
            ->assertSee('Групповая генерация команды')
            ->assertSee('Генерация хайлайтов игры')
            ->assertSee('100,00 RUB');
    }

    private function admin(): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
    }
}
