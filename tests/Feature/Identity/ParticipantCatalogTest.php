<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ParticipantCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_participant_catalog_uses_default_category_shell_and_header_group(): void
    {
        $player = $this->participant('player', 'Игрок', 'Каталога');
        $coach = $this->participant('coach', 'Тренер', 'Каталога');

        $this->get(route('participants.index'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('data-default-category', false)
            ->assertSee('participants-category-catalog', false)
            ->assertSee('Игрок Каталога')
            ->assertSee('Тренер Каталога')
            ->assertSee('Участники')
            ->assertSee(route('participants.players'), false)
            ->assertSee(route('participants.coaches'), false)
            ->assertSee(route('participants.index'), false)
            ->assertSee(route('teams.index'), false)
            ->assertSeeInOrder(['Игроки', 'Тренеры', 'Все участники', 'Команды']);

        $this->assertSame('/players', route('participants.players', [], false));
        $this->assertSame('/coaches', route('participants.coaches', [], false));
        $this->assertSame('/participants', route('participants.index', [], false));

        $this->assertNotNull($player);
        $this->assertNotNull($coach);
    }

    public function test_players_and_coaches_routes_apply_role_presets(): void
    {
        $player = $this->participant('player', 'Только', 'Игрок');
        $coach = $this->participant('coach', 'Только', 'Тренер');

        $this->get(route('participants.players'))
            ->assertOk()
            ->assertSee('Только Игрок')
            ->assertDontSee('Только Тренер')
            ->assertSee('Игрок');

        $this->get(route('participants.coaches'))
            ->assertOk()
            ->assertSee('Только Тренер')
            ->assertDontSee('Только Игрок')
            ->assertSee('Тренер');

        $this->assertNotNull($player);
        $this->assertNotNull($coach);
    }

    public function test_all_participants_page_can_filter_by_any_participation_role(): void
    {
        $player = $this->participant('player', 'Фильтр', 'Игрок');
        $referee = $this->participant('referee', 'Фильтр', 'Судья');

        $this->get(route('participants.index', ['role' => 'referee']))
            ->assertOk()
            ->assertSee('Фильтр Судья')
            ->assertDontSee('Фильтр Игрок')
            ->assertSee('value="referee" selected', false);

        $this->assertNotNull($player);
        $this->assertNotNull($referee);
    }

    public function test_catalog_search_uses_public_display_name_and_nickname(): void
    {
        $matching = $this->participant('player', 'Алексей', 'Снайпер');
        $matching->forceFill(['nickname' => 'three_point_king'])->save();
        $other = $this->participant('player', 'Борис', 'Центровой');

        $this->get(route('participants.index', ['q' => 'снайпер']))
            ->assertOk()
            ->assertSee('Алексей Снайпер')
            ->assertDontSee('Борис Центровой');

        $this->get(route('participants.index', ['q' => 'three_point']))
            ->assertOk()
            ->assertSee('Алексей Снайпер')
            ->assertSee('@three_point_king');

        $this->assertNotNull($other);
    }

    public function test_catalog_respects_discoverability_and_role_privacy(): void
    {
        $hidden = $this->participant('player', 'Скрытый', 'Игрок');
        $hidden->privacySettings()->updateOrCreate(
            ['type' => 'discoverability'],
            ['visibility' => UserPrivacyVisibilityEnum::NOBODY],
        );

        $privateRole = $this->participant('player', 'Закрытая', 'Роль');
        $privateRole->privacySettings()->updateOrCreate(
            ['type' => 'role_player'],
            ['visibility' => UserPrivacyVisibilityEnum::NOBODY],
        );

        $visible = $this->participant('player', 'Открытый', 'Игрок');

        $this->get(route('participants.players'))
            ->assertOk()
            ->assertSee('Открытый Игрок')
            ->assertDontSee('Скрытый Игрок')
            ->assertDontSee('Закрытая Роль');

        $this->assertNotNull($visible);
    }

    public function test_authenticated_participant_is_first_and_marked_as_own_profile(): void
    {
        $other = $this->participant('player', 'Алексей', 'Первый');
        $me = $this->participant('player', 'Яков', 'Последний');

        $response = $this->actingAs($me)
            ->get(route('participants.players'))
            ->assertOk()
            ->assertSee('Мой профиль')
            ->assertSeeInOrder(['Яков Последний', 'Алексей Первый']);

        $this->assertSame(2, substr_count($response->getContent(), 'participant-category-item__own-badge'));
        $this->assertNotNull($other);
    }

    public function test_catalog_supports_card_and_list_modes(): void
    {
        $this->participant('player', 'Режим', 'Отображения');

        $this->get(route('participants.index', ['view' => 'list']))
            ->assertOk()
            ->assertSee('data-default-category-view="list"', false)
            ->assertSee('participant-category-item--list', false)
            ->assertSee('data-default-category-results="cards"', false)
            ->assertSee('data-default-category-results="list"', false);
    }

    private function participant(string $role, string $firstName, string $lastName): User
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'username' => fake()->unique()->userName(),
        ]);
        $user->createProfile([
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
        $user->participationRoles(false)->create([
            'role' => $role,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(),
            'assigned_by' => $user->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);
        $user->privacySettings()->updateOrCreate(
            ['type' => 'role_'.$role],
            ['visibility' => UserPrivacyVisibilityEnum::EVERYONE],
        );

        return $user;
    }
}
