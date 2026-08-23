<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Identity\Domain\Enums\ActorTypeEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EventCreationWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_wizard_requires_authentication(): void
    {
        $this->get(route('events.wizard'))
            ->assertRedirect(route('login'));
    }

    public function test_wizard_renders_selected_entry_type_without_replacing_legacy_create_form(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        Actor::factory()->create([
            'type' => ActorTypeEnum::USER->value,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('events.wizard', ['type' => EventTypeEnum::TRAINING->value]))
            ->assertOk()
            ->assertSee('data-event-wizard', false)
            ->assertSee('Создать мероприятие')
            ->assertSee('value="training"', false)
            ->assertSee(route('events.create', ['type' => EventTypeEnum::TRAINING->value]), false);

        $this->actingAs($user)
            ->get(route('events.create', ['type' => EventTypeEnum::TRAINING->value]))
            ->assertOk()
            ->assertDontSee('data-event-wizard', false);
    }

    public function test_team_picker_exposes_invitable_teams_and_own_opted_out_team_but_hides_unrelated_opted_out_team(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $actor = Actor::factory()->create([
            'type' => ActorTypeEnum::USER->value,
            'user_id' => $user->id,
        ]);
        $otherUser = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $otherActor = Actor::factory()->create([
            'type' => ActorTypeEnum::USER->value,
            'user_id' => $otherUser->id,
        ]);

        $publicTeam = Team::query()->create([
            'created_by_actor_id' => $otherActor->id,
            'name' => 'Public Rockets',
            'alias' => 'public-rockets',
            'status' => TeamStatusEnum::ACTIVE,
            'accepts_competition_invitations' => true,
        ]);
        $hiddenTeam = Team::query()->create([
            'created_by_actor_id' => $otherActor->id,
            'name' => 'Private Rockets',
            'alias' => 'private-rockets',
            'status' => TeamStatusEnum::ACTIVE,
            'accepts_competition_invitations' => false,
        ]);
        $ownTeam = Team::query()->create([
            'created_by_actor_id' => $actor->id,
            'name' => 'Own Rockets',
            'alias' => 'own-rockets',
            'status' => TeamStatusEnum::ACTIVE,
            'accepts_competition_invitations' => false,
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('events.wizard.teams'))
            ->assertOk();

        $ids = collect($response->json('teams'))->pluck('id');
        $this->assertTrue($ids->contains($publicTeam->id));
        $this->assertTrue($ids->contains($ownTeam->id));
        $this->assertFalse($ids->contains($hiddenTeam->id));

        $ownPayload = collect($response->json('teams'))->firstWhere('id', $ownTeam->id);
        $this->assertTrue($ownPayload['manageable']);
        $this->assertFalse($ownPayload['accepts_invitations']);
    }
}
