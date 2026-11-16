<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\RentalInvoiceTemplates\Pages\ListRentalInvoiceTemplates;
use App\Filament\Backoffice\Resources\RentalInvoiceTemplates\RentalInvoiceTemplateResource;
use App\Filament\Backoffice\Resources\Venues\Pages\EditVenue;
use App\Filament\Backoffice\Resources\Venues\RelationManagers\CommercialMembershipsRelationManager;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class VenueRentalAdminParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.rental_flow', true);
        config()->set('features.venue_rental.invoices', true);
    }

    public function test_superadmin_can_access_invoice_templates_and_commercial_permissions_from_filament(): void
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $admin->createProfile([]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $venue = Venue::factory()->create();

        $this->assertTrue(RentalInvoiceTemplateResource::canViewAny());
        $this->assertTrue(RentalInvoiceTemplateResource::canCreate());

        Livewire::test(ListRentalInvoiceTemplates::class)->assertSuccessful()
            ->assertSee('Шаблоны счетов');
        Livewire::test(CommercialMembershipsRelationManager::class, [
            'ownerRecord' => $venue,
            'pageClass' => EditVenue::class,
        ])->assertSuccessful()
            ->mountAction(TestAction::make('grantCommercialRole')->table())
            ->assertMountedActionModalSee('Коммерческие права на площадку');
    }

    public function test_ordinary_user_cannot_manage_financial_templates_or_commercial_roles_in_filament(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $venue = Venue::factory()->create();

        $this->assertFalse(RentalInvoiceTemplateResource::canViewAny());
        $this->assertFalse(RentalInvoiceTemplateResource::canCreate());
        $this->assertFalse(CommercialMembershipsRelationManager::canViewForRecord($venue, EditVenue::class));
    }

    public function test_template_normalization_matches_public_scopes(): void
    {
        $attributes = [
            'scope' => 'venue', 'venue_id' => 26, 'owner_user_id' => 123,
            'name' => 'Basketpoint', 'title' => 'Аренда',
            'payment_methods' => ['cash', 'cash', 'bank_transfer'],
            'auto_send' => true, 'enabled' => true,
        ];
        $normalized = RentalInvoiceTemplateResource::normalizedData($attributes);
        $this->assertSame(26, $normalized['venue_id']);
        $this->assertNull($normalized['owner_user_id']);
        $this->assertSame(['cash', 'bank_transfer'], $normalized['payment_methods']);

        $global = RentalInvoiceTemplateResource::normalizedData([
            ...$attributes, 'scope' => 'global',
        ]);
        $this->assertNull($global['venue_id']);
        $this->assertNull($global['owner_user_id']);

        $this->expectException(\InvalidArgumentException::class);
        RentalInvoiceTemplateResource::normalizedData([
            ...$attributes, 'scope' => 'agent', 'owner_user_id' => null,
        ]);
    }
}
