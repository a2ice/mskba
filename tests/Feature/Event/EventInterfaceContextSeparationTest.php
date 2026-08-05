<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Enums\ActorTypeEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EventInterfaceContextSeparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_management_and_admin_event_contexts_are_separated(): void
    {
        $organizer = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $organizerActor = Actor::factory()->create([
            'type' => ActorTypeEnum::USER->value,
            'user_id' => $organizer->id,
        ]);
        $event = Event::factory()->create([
            'organizer_actor_id' => $organizerActor->id,
            'type' => EventTypeEnum::TRAINING->value,
            'title' => 'Разделённое мероприятие',
            'alias' => 'separated-event',
        ]);

        $managementUrl = route('events.management', $event->routeIdentifier());
        $participantManagementUrl = route('events.participants.manage.store', $event->routeIdentifier());

        $this->actingAs($organizer)
            ->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()
            ->assertSee($managementUrl, false)
            ->assertDontSee($participantManagementUrl, false)
            ->assertDontSee('data-event-participant-manager', false);

        $this->actingAs($organizer)
            ->get($managementUrl)
            ->assertOk()
            ->assertSee('data-event-participant-manager', false)
            ->assertSee('data-event-participant-search', false)
            ->assertSee('data-event-participant-user-id', false)
            ->assertSee($participantManagementUrl, false);

        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
        Actor::factory()->create([
            'type' => ActorTypeEnum::USER->value,
            'user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get($managementUrl)
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.events.show', $event))
            ->assertOk()
            ->assertSee('Административный контекст');

        $regularUser = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->actingAs($regularUser)
            ->get(route('admin.events.show', $event))
            ->assertForbidden();
    }
}
