<?php

namespace Tests\Feature\SportsSection;

use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Application\UseCases\CreateSportsSectionHandler;
use App\Modules\SportsSection\Application\UseCases\ManageSectionCoachHandler;
use App\Modules\SportsSection\Application\UseCases\ManageSectionTraineeHandler;
use App\Modules\SportsSection\Domain\Enums\SportsSectionJoinRequestStatusEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\SportsSection\Domain\Models\SportsSectionJoinRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SportsSectionApplicationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.sports_sections.enabled', true);
    }

    public function test_confirmed_player_applies_once_and_owner_accepts_atomically(): void
    {
        [$owner, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        [$player] = $this->roleUser(UserParticipationRoleEnum::PLAYER);
        $section = $this->activeSection($actor, ['accepts_trainee_requests' => true]);

        $this->actingAs($player)
            ->post(route('sports-sections.applications.store', $section))
            ->assertRedirect()
            ->assertSessionHas('status');

        $application = SportsSectionJoinRequest::query()->sole();
        $this->assertSame(SportsSectionJoinRequestStatusEnum::PENDING, $application->status);

        $this->actingAs($player)
            ->post(route('sports-sections.applications.store', $section))
            ->assertRedirect()
            ->assertSessionHasErrors('section');
        $this->assertSame(1, SportsSectionJoinRequest::query()->count());

        $this->actingAs($owner)
            ->get(route('account.sports-sections.applications', $section))
            ->assertOk()
            ->assertSee('Заявки и набор')
            ->assertSee('На рассмотрении');

        $this->actingAs($owner)
            ->patch(route('account.sports-sections.applications.respond', [$section, $application]), ['action' => 'accept'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(SportsSectionJoinRequestStatusEnum::ACCEPTED, $application->fresh()->status);
        $this->assertDatabaseHas('section_trainee_memberships', [
            'sports_section_id' => $section->id,
            'user_id' => $player->id,
            'status' => 'active',
        ]);

        $this->actingAs($player)
            ->post(route('sports-sections.applications.store', $section))
            ->assertRedirect()
            ->assertSessionHasErrors('section');
        $this->assertSame(1, SportsSectionJoinRequest::query()->count());
    }

    public function test_canonical_identity_cannot_create_second_pending_application_and_only_owner_can_cancel(): void
    {
        [, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        [$player] = $this->roleUser(UserParticipationRoleEnum::PLAYER);
        $alias = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $alias->forceFill(['canonical_user_id' => $player->id])->save();
        [$stranger] = $this->roleUser(UserParticipationRoleEnum::PLAYER);
        $section = $this->activeSection($actor, ['accepts_trainee_requests' => true]);

        $this->actingAs($player)->post(route('sports-sections.applications.store', $section))->assertRedirect();
        $application = SportsSectionJoinRequest::query()->sole();

        $this->actingAs($alias)
            ->post(route('sports-sections.applications.store', $section))
            ->assertRedirect()
            ->assertSessionHasErrors('section');
        $this->assertSame(1, SportsSectionJoinRequest::query()->count());

        $this->actingAs($stranger)
            ->patch(route('sports-sections.applications.cancel', [$section, $application]))
            ->assertRedirect()
            ->assertSessionHasErrors('section');
        $this->assertSame(SportsSectionJoinRequestStatusEnum::PENDING, $application->fresh()->status);

        $this->actingAs($alias)
            ->patch(route('sports-sections.applications.cancel', [$section, $application]))
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->assertSame(SportsSectionJoinRequestStatusEnum::CANCELLED, $application->fresh()->status);
    }

    public function test_recruiting_auto_enables_applications_and_catalog_shows_single_recruitment_badge(): void
    {
        [$owner, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        $recruiting = $this->activeSection($actor, ['name' => 'Секция активного набора']);
        $closed = $this->activeSection($actor, ['name' => 'Закрытая секция']);

        $this->actingAs($owner)
            ->patch(route('account.sports-sections.applications.settings', $recruiting), [
                'accepts_trainee_requests' => 0,
                'is_recruiting' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $recruiting->refresh();
        $this->assertTrue($recruiting->accepts_trainee_requests);
        $this->assertTrue($recruiting->is_recruiting);

        $this->get(route('sports-sections.index', ['recruiting' => 1]))
            ->assertOk()
            ->assertSee('Секция активного набора')
            ->assertDontSee('Закрытая секция')
            ->assertSee('Идёт набор')
            ->assertDontSee('Принимает заявки');

        $this->get(route('sports-sections.index', ['accepts_requests' => 1]))
            ->assertOk()
            ->assertSee('Секция активного набора')
            ->assertDontSee('Закрытая секция');

        $this->app['auth']->guard()->logout();
        $this->get(route('sports-sections.show', $recruiting))
            ->assertOk()
            ->assertSee('Идёт набор')
            ->assertDontSee('Принимает заявки')
            ->assertSee('data-modal-target="auth-entry-classic"', false);
    }

    public function test_capacity_stops_new_application_and_acceptance_when_last_place_is_taken(): void
    {
        [$owner, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        [$first] = $this->roleUser(UserParticipationRoleEnum::PLAYER);
        [$second] = $this->roleUser(UserParticipationRoleEnum::PLAYER);
        $section = $this->activeSection($actor, [
            'accepts_trainee_requests' => true,
            'max_trainees' => 1,
        ]);

        app(ManageSectionTraineeHandler::class)->activate($section, $first, $owner);

        $this->actingAs($second)
            ->post(route('sports-sections.applications.store', $section))
            ->assertRedirect()
            ->assertSessionHasErrors('section');

        $this->assertDatabaseCount('sports_section_join_requests', 0);
    }

    public function test_coach_without_trainee_permission_cannot_manage_or_process_applications(): void
    {
        [$owner, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        [$manager] = $this->roleUser(UserParticipationRoleEnum::COACH);
        [$player] = $this->roleUser(UserParticipationRoleEnum::PLAYER);
        $section = $this->activeSection($actor, ['accepts_trainee_requests' => true]);
        app(ManageSectionCoachHandler::class)->add($section, $manager, $owner, [SportsSectionPermissionEnum::MANAGE]);

        $this->actingAs($player)->post(route('sports-sections.applications.store', $section))->assertRedirect();
        $application = SportsSectionJoinRequest::query()->sole();

        $this->actingAs($manager)
            ->get(route('account.sports-sections.applications', $section))
            ->assertForbidden();

        $this->actingAs($manager)
            ->patch(route('account.sports-sections.applications.respond', [$section, $application]), ['action' => 'accept'])
            ->assertRedirect()
            ->assertSessionHasErrors('section');
        $this->assertSame(SportsSectionJoinRequestStatusEnum::PENDING, $application->fresh()->status);
    }

    public function test_non_player_cannot_apply_to_open_section(): void
    {
        [, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        [$coach] = $this->roleUser(UserParticipationRoleEnum::COACH);
        $section = $this->activeSection($actor, ['accepts_trainee_requests' => true]);

        $this->actingAs($coach)
            ->post(route('sports-sections.applications.store', $section))
            ->assertRedirect()
            ->assertSessionHasErrors('section');
        $this->assertDatabaseCount('sports_section_join_requests', 0);
    }

    /** @return array{User, Actor} */
    private function roleUser(UserParticipationRoleEnum $role): array
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $user->participationRoles(false)->create([
            'role' => $role,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(),
            'assigned_by' => $user->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);
        $actor = app(CurrentActorResolver::class)->resolve($user, null);

        return [$user, $actor];
    }

    /** @param array<string, mixed> $overrides */
    private function activeSection(Actor $actor, array $overrides = []): SportsSection
    {
        $section = app(CreateSportsSectionHandler::class)->handle($actor, array_replace([
            'name' => 'Секция '.fake()->unique()->numberBetween(1, 999999),
            'description' => 'Регулярные тренировки.',
            'training_mode' => 'group',
            'game_format' => 'basketball',
            'pricing_type' => 'free',
            'single_session_price_minor' => null,
            'currency' => 'RUB',
            'contact_source' => 'head_coach',
        ], $overrides));
        $section->update([
            'status' => 'active',
            'accepts_trainee_requests' => (bool) ($overrides['accepts_trainee_requests'] ?? false),
            'is_recruiting' => (bool) ($overrides['is_recruiting'] ?? false),
            'max_trainees' => $overrides['max_trainees'] ?? null,
        ]);

        return $section->refresh();
    }
}
