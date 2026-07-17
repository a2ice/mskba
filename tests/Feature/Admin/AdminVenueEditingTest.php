<?php

namespace Tests\Feature\Admin;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminVenueEditingTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_edit_confirmed_venue_from_admin_panel(): void
    {
        $superadmin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $venue = Venue::factory()->create([
            'name' => 'Старое название',
            'status' => VenueStatusEnum::CONFIRMED,
            'type' => VenueTypeEnum::STREET_COURT,
        ]);

        $this->actingAs($superadmin)
            ->get(route('admin.venues.edit', $venue))
            ->assertOk()
            ->assertSee('Старое название');

        $this->actingAs($superadmin)
            ->put(route('admin.venues.update', $venue), [
                'name' => 'Новое название',
                'type' => VenueTypeEnum::SPORTS_HALL->value,
                'requires_payment' => '1',
                'requires_booking_approval' => '0',
                'short_description' => 'Новое краткое описание',
                'full_description' => 'Новое полное описание',
                'tags' => 'паркет, раздевалки',
            ])
            ->assertRedirect(route('admin.venues.edit', $venue))
            ->assertSessionHas('success');

        $venue->refresh();

        $this->assertSame('Новое название', $venue->name);
        $this->assertSame(VenueTypeEnum::SPORTS_HALL, $venue->type);
        $this->assertSame(VenueStatusEnum::CONFIRMED, $venue->status);
        $this->assertTrue($venue->requires_payment);
        $this->assertFalse($venue->requires_booking_approval);
        $this->assertEqualsCanonicalizing(['паркет', 'раздевалки'], $venue->tags()->pluck('name')->all());
    }

    public function test_admin_cannot_use_superadmin_venue_editing_routes(): void
    {
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $venue = Venue::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.venues.edit', $venue))
            ->assertForbidden();

        $this->actingAs($admin)
            ->put(route('admin.venues.update', $venue), [
                'name' => 'Недопустимое изменение',
                'type' => VenueTypeEnum::STREET_COURT->value,
            ])
            ->assertForbidden();
    }

    public function test_deleted_venue_cannot_be_edited_by_superadmin(): void
    {
        $superadmin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $venue = Venue::factory()->create();
        $venue->delete();

        $this->actingAs($superadmin)
            ->get(route('admin.venues.edit', $venue->id))
            ->assertNotFound();
    }

    private function user(UserSystemRoleEnum $role): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => $role,
        ]);
    }
}
