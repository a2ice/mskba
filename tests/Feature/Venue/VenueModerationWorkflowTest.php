<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueModerationRequestStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueModerationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VenueModerationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_submit_venue_to_moderation_and_see_rejection_reason(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
        $venue = Venue::factory()->create([
            'created_by_actor_id' => app(CurrentActorResolver::class)->resolve($owner, null)->id,
            'name' => 'На Дубнинской',
            'alias' => 'na-dubninskoi',
            'status' => VenueStatusEnum::UNCONFIRMED,
            'type' => VenueTypeEnum::STREET_COURT,
        ]);

        $this
            ->actingAs($owner)
            ->get(route('venues.edit', $venue->alias))
            ->assertOk()
            ->assertSee('Отправить на модерацию');

        $this
            ->actingAs($owner)
            ->post(route('venues.moderation.submit', $venue->alias), [
                'message' => 'Проверьте, пожалуйста.',
            ])
            ->assertRedirect(route('venues.edit', $venue->alias));

        $request = VenueModerationRequest::query()->firstOrFail();

        $this->assertSame(VenueModerationRequestStatusEnum::PENDING, $request->status);

        $this
            ->actingAs($admin)
            ->post(route('admin.venues.moderation.reject', $request), [
                'message' => 'Исправьте опечатку в адресе.',
            ])
            ->assertRedirect(route('admin.venues'));

        $this->assertDatabaseHas('venue_moderation_requests', [
            'id' => $request->id,
            'status' => VenueModerationRequestStatusEnum::REJECTED->value,
            'reviewed_by_user_id' => $admin->id,
        ]);

        $this
            ->actingAs($owner)
            ->get(route('venues.edit', $venue->alias))
            ->assertOk()
            ->assertSee('Отклонена')
            ->assertSee('Исправьте опечатку в адресе.');
    }

    public function test_admin_can_block_venue_from_moderation_request(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
        $venue = Venue::factory()->create([
            'created_by_actor_id' => app(CurrentActorResolver::class)->resolve($owner, null)->id,
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);

        $this
            ->actingAs($owner)
            ->post(route('venues.moderation.submit', $venue->alias))
            ->assertRedirect(route('venues.edit', $venue->alias));

        $request = VenueModerationRequest::query()->firstOrFail();

        $this
            ->actingAs($admin)
            ->post(route('admin.venues.moderation.block', $request), [
                'message' => 'Повторная отправка без исправлений.',
            ])
            ->assertRedirect(route('admin.venues'));

        $this->assertDatabaseHas('venues', [
            'id' => $venue->id,
            'status' => VenueStatusEnum::BLOCKED->value,
            'status_info' => 'Повторная отправка без исправлений.',
        ]);

        $this
            ->actingAs($owner)
            ->get(route('venues.edit', $venue->alias))
            ->assertOk()
            ->assertSee('Площадка заблокирована')
            ->assertDontSee('Отправить на модерацию');
    }

    public function test_edit_page_uses_current_owner_version_when_alias_is_shared_by_duplicates(): void
    {
        $otherOwner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        Venue::factory()->create([
            'created_by_actor_id' => app(CurrentActorResolver::class)->resolve($otherOwner, null)->id,
            'name' => 'Чужая версия',
            'alias' => 'shared-venue',
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);

        $ownVenue = Venue::factory()->create([
            'created_by_actor_id' => app(CurrentActorResolver::class)->resolve($owner, null)->id,
            'name' => 'Моя версия',
            'alias' => 'shared-venue',
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);

        $this
            ->actingAs($owner)
            ->get(route('venues.edit', $ownVenue->alias))
            ->assertOk()
            ->assertSee('Моя версия')
            ->assertDontSee('Чужая версия');
    }
}
