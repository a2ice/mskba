<?php

namespace Tests\Feature\Identity;

use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Application\Services\PublicUserProfileService;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserPrivacySetting;
use App\Modules\SportsSection\Application\UseCases\CreateSportsSectionHandler;
use App\Modules\SportsSection\Application\UseCases\ManageSportsSectionJoinRequestHandler;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\SportsSection\Domain\Models\SectionTraineeMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicUserProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_closed_profile_and_private_role_do_not_leak_to_guests(): void
    {
        $user = $this->user('player');
        $this->get('/users/'.$user->username)->assertOk();
        $this->get('/users/'.$user->username.'/player')->assertNotFound();
        $this->privacy($user, 'profile', 'nobody');
        $this->get('/users/'.$user->username)->assertNotFound();
        $this->getJson('/users/'.$user->username.'/preview')->assertNotFound();
        $this->actingAs($user)->get('/users/'.$user->username.'/player')->assertOk();
    }

    public function test_player_blocks_are_independent_and_api_only_returns_basic_information(): void
    {
        $user = $this->user('player');
        $user->playerProfile()->create(['height_cm' => 198, 'comment' => 'Люблю быстрый баскетбол.', 'extra' => ['secret' => 'PRIVATE EXTRA']]);
        $this->privacy($user, 'role_player', 'everyone');
        $this->privacy($user, 'avatar', 'nobody');
        $service = app(PublicUserProfileService::class);
        $this->assertSame([], $service->page($user, null, 'player')['blocks']);
        $this->privacy($user, 'player_characteristics', 'everyone');
        $this->privacy($user, 'player_teams', 'everyone');
        $this->privacy($user, 'player_games', 'everyone');
        $this->privacy($user, 'player_tournaments', 'everyone');
        $data = $service->page($user->fresh(), null, 'player');
        $this->assertCount(5, $data['blocks']);
        $this->assertStringContainsString('198', json_encode($data));
        $this->assertSame('Люблю быстрый баскетбол.', $data['roles'][0]['description']);
        $this->assertStringNotContainsString('PRIVATE EXTRA', json_encode($data));
        $response = $this->getJson('/users/'.$user->username.'/preview')->assertOk()->assertJsonPath('user.avatar_url', null)->assertJsonPath('user.avatar_restricted', true);
        $this->assertSame(['name', 'avatar_url', 'avatar_restricted', 'url', 'public_coach', 'role_label', 'sections'], array_keys($response->json('user')));
        $this->assertStringNotContainsString($user->password, $response->getContent());
        $this->get('/users/'.$user->username.'/player')->assertOk();
    }

    public function test_selected_viewer_can_open_profile_but_not_private_player_page(): void
    {
        $user = $this->user('player');
        $viewer = User::factory()->create();
        $setting = $this->privacy($user, 'profile', 'selected_users');
        $setting->allowedUsers()->attach($viewer);
        $this->get('/users/'.$user->username)->assertNotFound();
        $this->actingAs($viewer)->get('/users/'.$user->username)->assertOk();
        $this->get('/users/'.$user->username.'/player')->assertNotFound();
    }

    public function test_active_public_coach_exposes_only_mandatory_identity_and_sections(): void
    {
        $user = $this->user('coach');
        $section = $this->section($user);
        foreach (['profile', 'avatar', 'role_coach', 'coach_sections', 'contacts'] as $type) {
            $this->privacy($user, $type, 'nobody');
        }
        $this->getJson('/users/'.$user->username.'/preview')->assertOk()
            ->assertJsonPath('user.public_coach', true)
            ->assertJsonPath('user.avatar_url', null)
            ->assertJsonPath('user.avatar_restricted', true)
            ->assertJsonPath('user.sections.0.name', $section->name);
        $this->get('/users/'.$user->username.'/coach')->assertOk()
            ->assertSee($section->name)
            ->assertSee('Отображение аватара запрещено в настройках профиля')
            ->assertDontSee($user->password);
        $section->forceFill(['status' => 'paused'])->save();
        $this->getJson('/users/'.$user->username.'/preview')->assertNotFound();
    }

    public function test_expired_membership_and_inactive_role_remove_public_coach_exception(): void
    {
        $user = $this->user('coach');
        $section = $this->section($user);
        $this->privacy($user, 'profile', 'nobody');
        $section->headCoachMembership->contract->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->getJson('/users/'.$user->username.'/preview')->assertNotFound();
        $section->headCoachMembership->contract->forceFill(['expires_at' => null])->save();
        $user->participationRoles(false)->update(['status' => 'inactive']);
        $this->getJson('/users/'.$user->username.'/preview')->assertNotFound();
    }

    public function test_blocked_and_deleted_users_and_unknown_roles_are_unavailable(): void
    {
        $user = $this->user('coach');
        $this->section($user);
        $this->get('/users/'.$user->username.'/unknown')->assertNotFound();
        $user->forceFill(['status' => 'blocked'])->save();
        $this->getJson('/users/'.$user->username.'/preview')->assertNotFound();
        $user->delete();
        $this->get('/users/'.$user->username)->assertNotFound();
    }

    public function test_organizer_cannot_apply_to_own_section_even_with_player_role(): void
    {
        $user = $this->user('coach');
        $user->participationRoles(false)->create(['role' => 'player', 'status' => UserParticipationRoleStatusEnum::ACTIVE, 'assigned_at' => now(), 'assigned_by' => $user->id, 'assigner' => UserParticipationRoleAssignerEnum::USER]);
        $section = $this->section($user);
        $this->actingAs($user)->get(route('sports-sections.show', $section))->assertOk()->assertViewHas('canApply', false);
        $this->expectException(SportsSectionException::class);
        app(ManageSportsSectionJoinRequestHandler::class)->submit($section, $user);
    }

    public function test_own_public_profile_has_account_shortcut_only_for_owner(): void
    {
        $user = $this->user('player');

        $this->get('/users/'.$user->username)
            ->assertOk()
            ->assertDontSee('Перейти в аккаунт');

        $this->actingAs($user)
            ->get('/users/'.$user->username)
            ->assertOk()
            ->assertSee('Перейти в аккаунт')
            ->assertSee(route('account'), false);
    }

    public function test_legacy_account_without_username_has_working_public_link(): void
    {
        $user = $this->user('coach');
        $user->forceFill(['username' => null])->save();
        $this->section($user);
        $this->get('/users/id/'.$user->id)->assertOk();
        $this->getJson('/users/id/'.$user->id.'/preview')->assertOk();
    }

    public function test_player_cards_use_avatar_and_respect_section_membership_privacy(): void
    {
        $owner = $this->user('coach');
        $section = $this->section($owner);
        $player = $this->user('player');
        $profile = $player->createProfile(['first_name' => 'Игрок', 'last_name' => 'Секции']);
        $profile->media()->create(['collection' => 'avatar', 'disk' => 'public', 'path' => 'avatars/player.webp', 'is_featured' => true]);
        SectionTraineeMembership::query()->create(['sports_section_id' => $section->id, 'user_id' => $player->id, 'status' => 'active', 'joined_at' => now()]);
        $this->get(route('sports-sections.show', $section))->assertOk()->assertViewHas('publicPlayers', fn ($members) => $members->isEmpty());
        foreach (['role_player', 'player_sections'] as $type) {
            $this->privacy($player, $type, 'everyone');
        }
        $this->get(route('sports-sections.show', $section))->assertOk()->assertViewHas('publicPlayers', fn ($members) => $members->count() === 1);
        $this->getJson('/users/'.$player->username.'/preview')->assertOk()->assertJsonPath('user.avatar_url', '/storage/avatars/player.webp');
        $this->privacy($player, 'avatar', 'nobody');
        $this->getJson('/users/'.$player->username.'/preview')->assertOk()->assertJsonPath('user.avatar_url', null)->assertJsonPath('user.avatar_restricted', true);
        $this->privacy($player, 'profile', 'nobody');
        $this->get(route('sports-sections.show', $section))->assertOk()->assertViewHas('publicPlayers', fn ($members) => $members->isEmpty());
    }

    public function test_games_require_actual_play_and_public_completed_event(): void
    {
        $player = $this->user('player');
        foreach (['role_player', 'player_games'] as $type) {
            $this->privacy($player, $type, 'everyone');
        }
        foreach (['public', 'private'] as $visibility) {
            $event = Event::factory()->create(['visibility' => $visibility]);
            $game = Game::query()->create(['event_id' => $event->id, 'created_by_actor_id' => $event->organizer_actor_id, 'status' => 'completed', 'side_a_size' => 1, 'side_b_size' => 1]);
            $side = $game->sides()->create(['slot' => 'A', 'display_name' => 'Команда']);
            $game->rosterEntries()->create(['game_side_id' => $side->id, 'user_id' => $player->id, 'status' => 'played', 'lineup_role' => 'starter']);
        }
        $blocks = app(PublicUserProfileService::class)->page($player, null, 'player')['blocks'];
        $this->assertCount(1, $blocks[0]['items']);
        $game->rosterEntries()->update(['status' => 'did_not_play']);
        $event->forceFill(['visibility' => 'public'])->save();
        $blocks = app(PublicUserProfileService::class)->page($player, null, 'player')['blocks'];
        $this->assertCount(1, $blocks[0]['items']);
    }

    public function test_specialist_pages_require_their_own_privacy_and_owner_sees_relevant_blocks(): void
    {
        foreach (['media', 'venue_related', 'referee', 'statistician'] as $role) {
            $user = $this->user($role);
            $this->get('/users/'.$user->username.'/'.$role)->assertNotFound();
            $this->actingAs($user)->get('/users/'.$user->username.'/'.$role)->assertOk();
            $this->assertCount(1, app(PublicUserProfileService::class)->page($user, $user, $role)['blocks']);
            $this->app['auth']->forgetGuards();
        }
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'confirmed', 'username' => fake()->unique()->userName(), 'password' => 'private-password']);
        $user->participationRoles(false)->create(['role' => $role, 'status' => UserParticipationRoleStatusEnum::ACTIVE, 'assigned_at' => now(), 'assigned_by' => $user->id, 'assigner' => UserParticipationRoleAssignerEnum::USER]);

        return $user;
    }

    private function section(User $user)
    {
        config()->set('features.sports_sections.enabled', true);
        $actor = app(CurrentActorResolver::class)->resolve($user, null);
        $section = app(CreateSportsSectionHandler::class)->handle($actor, ['name' => 'Открытая школа броска', 'description' => 'Тренировки', 'training_mode' => 'group', 'game_format' => 'basketball', 'pricing_type' => 'free', 'currency' => 'RUB', 'contact_source' => 'head_coach']);
        $section->forceFill(['status' => 'active', 'accepts_trainee_requests' => true])->save();

        return $section->fresh();
    }

    private function privacy(User $user, string $type, string $visibility): UserPrivacySetting
    {
        return $user->privacySettings()->updateOrCreate(['type' => $type], ['visibility' => $visibility]);
    }
}
