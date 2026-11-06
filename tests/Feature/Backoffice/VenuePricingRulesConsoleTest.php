<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\Venues\Pages\EditVenue;
use App\Filament\Backoffice\Resources\Venues\RelationManagers\PricingRulesRelationManager;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\Services\VenuePricingRuleManager;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class VenuePricingRulesConsoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_pricing_constructor_opens_in_filament_even_when_rental_flow_is_disabled(): void
    {
        config()->set('features.venue_rental.rental_flow', false);

        $admin = User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $admin->createProfile([]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $venue = Venue::factory()->create();

        Livewire::test(PricingRulesRelationManager::class, [
            'ownerRecord' => $venue,
            'pageClass' => EditVenue::class,
        ])->assertSuccessful()
            ->assertSee('Тарифные условия ещё не добавлены')
            ->assertSee('Настроить тарифы')
            ->mountAction(TestAction::make('managePricingRules')->table())
            ->assertMountedActionModalSee('Новое тарифное условие')
            ->assertMountedActionModalSee('Основное')
            ->assertMountedActionModalSee('Условия')
            ->assertMountedActionModalSee('Формула');
    }

    public function test_existing_published_rule_is_editable_as_new_draft_in_native_modal(): void
    {
        $admin = User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $admin->createProfile([]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $venue = Venue::factory()->create();
        $manager = app(VenuePricingRuleManager::class);
        $rule = $manager->saveDraft($venue, $admin, [
            'name' => 'Скидка при аренде 2 часов',
            'priority' => 100,
            'is_enabled' => true,
            'stop_processing' => false,
            'scope' => 'all',
            'min_duration_minutes' => 120,
            'operation' => 'percent_discount',
            'value_text' => '30',
        ]);
        $manager->publish($venue, $admin, $rule);

        Livewire::test(PricingRulesRelationManager::class, [
            'ownerRecord' => $venue,
            'pageClass' => EditVenue::class,
        ])->assertSuccessful()
            ->assertSee('Скидка при аренде 2 часов')
            ->mountAction(TestAction::make('editPricingRule')->table($rule))
            ->assertMountedActionModalSee('Новая редакция условия')
            ->assertMountedActionModalSee('Формула');
    }
}
