<?php

namespace Tests\Feature\Admin;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Moderation\Domain\Enums\ModerationRequestStatusEnum;
use App\Modules\Moderation\Domain\Enums\ModerationTypeEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Amenity;
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
            'requires_payment' => true,
            'requires_booking_approval' => true,
        ]);

        $this->actingAs($superadmin)
            ->get(route('admin.venues.edit', $venue))
            ->assertOk()
            ->assertSee('Старое название')
            ->assertSee('Кольца, покрытие и разметка')
            ->assertSee('Оснащение и удобства');

        $this->actingAs($superadmin)
            ->put(route('admin.venues.update', $venue), [
                'name' => 'Новое название',
                'type' => VenueTypeEnum::SPORTS_HALL->value,
                'requires_payment' => '0',
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
        $this->assertTrue($venue->requires_booking_approval);
        $this->assertEqualsCanonicalizing(['паркет', 'раздевалки'], $venue->tags()->pluck('name')->all());
    }

    public function test_superadmin_can_edit_venue_facilities_from_admin_panel(): void
    {
        $superadmin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'type' => VenueTypeEnum::SPORTS_HALL,
        ]);
        $shower = Amenity::query()->where('alias', 'shower')->firstOrFail();

        $this->actingAs($superadmin)
            ->put(route('admin.venues.update', $venue), [
                'facilities_present' => '1',
                'name' => $venue->name,
                'type' => $venue->type->value,
                'short_description' => $venue->short_description,
                'full_description' => $venue->full_description,
                'characteristics' => [
                    'hoops_count' => 2,
                    'hoops_condition' => 5,
                    'surface_condition' => 4,
                    'marking_condition' => 'good',
                ],
                'amenity_ids' => [$shower->id],
            ])
            ->assertRedirect(route('admin.venues.edit', $venue))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('venue_characteristics', [
            'venue_id' => $venue->id,
            'hoops_count' => 2,
            'hoops_condition' => 5,
            'surface_condition' => 4,
            'marking_condition' => 'good',
        ]);
        $this->assertDatabaseHas('venue_amenities', [
            'venue_id' => $venue->id,
            'amenity_id' => $shower->id,
        ]);
    }

    public function test_admin_routes_identify_venues_by_id_when_aliases_match(): void
    {
        $superadmin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        Venue::factory()->create([
            'name' => 'Первая площадка',
            'alias' => 'shared-alias',
        ]);
        $secondVenue = Venue::factory()->create([
            'name' => 'Вторая площадка',
            'alias' => 'shared-alias',
        ]);

        $editUrl = route('admin.venues.edit', $secondVenue);

        $this->assertStringEndsWith("/admin/venues/{$secondVenue->id}/edit", $editUrl);

        $this->actingAs($superadmin)
            ->get($editUrl)
            ->assertOk()
            ->assertSee('Вторая площадка')
            ->assertDontSee('Первая площадка');
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

    public function test_superadmin_can_manage_venue_schedule_from_admin_panel(): void
    {
        $superadmin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);

        $this->actingAs($superadmin)
            ->get(route('admin.venues.schedule.edit', $venue))
            ->assertOk()
            ->assertSee('Расписание площадки');

        $this->actingAs($superadmin)
            ->put(route('admin.venues.schedule.update', $venue), [
                'timezone' => 'Europe/Moscow',
                'intervals' => [1 => [['starts_at' => '08:00', 'ends_at' => '22:00']]],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('venue_schedules', ['venue_id' => $venue->id]);
    }

    public function test_superadmin_can_edit_venue_while_moderation_is_pending(): void
    {
        $superadmin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $venue = Venue::factory()->create(['name' => 'До исправления']);
        $venue->moderationRequests()->create([
            'type' => ModerationTypeEnum::VENUE,
            'status' => ModerationRequestStatusEnum::PENDING,
            'submitted_at' => now(),
        ]);

        $this->actingAs($superadmin)
            ->put(route('admin.venues.update', $venue), [
                'name' => 'Исправлено суперадмином',
                'type' => $venue->type->value,
            ])
            ->assertRedirect(route('admin.venues.edit', $venue))
            ->assertSessionHas('success');

        $this->assertSame('Исправлено суперадмином', $venue->refresh()->name);
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
