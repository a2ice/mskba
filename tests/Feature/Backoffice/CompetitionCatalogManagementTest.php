<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\Competitions\CompetitionCatalogEditor;
use App\Filament\Backoffice\Resources\Leagues\LeagueResource;
use App\Filament\Backoffice\Resources\LeagueSeasons\LeagueSeasonResource;
use App\Filament\Backoffice\Resources\Tournaments\TournamentResource;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tournament\Domain\Models\League;
use App\Modules\Tournament\Domain\Models\LeagueSeason;
use App\Modules\Tournament\Domain\Models\Tournament;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CompetitionCatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserSystemRoleEnum $role): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => $role,
        ]);
    }

    private function login(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
    }

    public function test_superadmin_can_open_all_three_crud_resources(): void
    {
        $this->login($this->user(UserSystemRoleEnum::SUPERADMIN));

        foreach ([LeagueResource::class, LeagueSeasonResource::class, TournamentResource::class] as $resource) {
            $this->assertTrue($resource::canViewAny());
            $this->assertTrue($resource::canCreate());
            $this->get($resource::getUrl('index'))->assertOk();
            $this->get($resource::getUrl('create'))->assertOk();
        }
    }

    public function test_league_does_not_require_a_season_and_season_dates_are_optional(): void
    {
        $this->login($this->user(UserSystemRoleEnum::SUPERADMIN));
        $editor = app(CompetitionCatalogEditor::class);

        $league = $editor->createLeague(['name' => 'Московская баскетбольная лига']);
        $this->assertSame('active', $league->status);
        $this->assertSame(0, $league->seasons()->count());

        $season = $editor->createSeason([
            'league_id' => $league->id, 'name' => 'Сезон без дат',
        ]);
        $this->assertNull($season->starts_on);
        $this->assertNull($season->ends_on);
        $this->assertSame($league->id, $season->league_id);

        $independent = $editor->createTournament([
            'title' => 'Независимый кубок', 'starts_on' => '2026-11-21',
        ]);
        $this->assertNull($independent->league_id);
        $this->assertNull($independent->league_season_id);

        $leagueOnly = $editor->createTournament([
            'title' => 'Открытый турнир лиги', 'starts_on' => '2026-11-22',
            'league_id' => $league->id,
        ]);
        $this->assertSame($league->id, $leagueOnly->league_id);
        $this->assertNull($leagueOnly->league_season_id);

        $linked = $editor->createTournament([
            'title' => 'Первенство сезона', 'starts_on' => '2026-12-01',
            'league_id' => $league->id, 'league_season_id' => $season->id,
        ]);
        $this->assertSame($season->id, $linked->season->id);
        $this->assertSame($league->id, $linked->league->id);
    }

    public function test_linked_tournament_cannot_choose_a_season_from_another_league(): void
    {
        $this->login($this->user(UserSystemRoleEnum::SUPERADMIN));
        $editor = app(CompetitionCatalogEditor::class);
        $first = $editor->createLeague(['name' => 'Первая лига']);
        $second = $editor->createLeague(['name' => 'Вторая лига']);
        $season = $editor->createSeason(['league_id' => $second->id, 'name' => '2026/27']);

        $this->expectException(ValidationException::class);
        $editor->createTournament([
            'title' => 'Несогласованная связь', 'starts_on' => '2026-12-01',
            'league_id' => $first->id, 'league_season_id' => $season->id,
        ]);
    }

    public function test_season_end_can_be_open_but_cannot_precede_start(): void
    {
        $this->login($this->user(UserSystemRoleEnum::SUPERADMIN));
        $editor = app(CompetitionCatalogEditor::class);
        $league = $editor->createLeague(['name' => 'Периодическая лига']);
        $season = $editor->createSeason([
            'league_id' => $league->id, 'name' => 'Бессрочный сезон',
            'starts_on' => '2026-11-01',
        ]);
        $this->assertNull($season->ends_on);

        $this->expectException(ValidationException::class);
        $editor->updateSeason($season, ['ends_on' => '2026-10-01']);
    }

    public function test_competition_history_prevents_deleting_linked_leagues_and_seasons(): void
    {
        $this->login($this->user(UserSystemRoleEnum::SUPERADMIN));
        $editor = app(CompetitionCatalogEditor::class);
        $league = $editor->createLeague(['name' => 'Историческая лига']);
        $season = $editor->createSeason(['league_id' => $league->id, 'name' => '2026']);
        $tournament = $editor->createTournament([
            'title' => 'Архивируемый турнир', 'starts_on' => '2026-12-01',
            'league_id' => $league->id, 'league_season_id' => $season->id,
        ]);

        try {
            $editor->deleteSeason($season);
            $this->fail('Linked season was deleted.');
        } catch (ValidationException $e) {
            $this->assertDatabaseHas('league_seasons', ['id' => $season->id, 'deleted_at' => null]);
        }
        try {
            $editor->deleteLeague($league);
            $this->fail('Linked league was deleted.');
        } catch (ValidationException $e) {
            $this->assertDatabaseHas('leagues', ['id' => $league->id, 'deleted_at' => null]);
        }

        $editor->updateLeague($league, ['status' => 'archived']);
        $editor->updateSeason($season, ['status' => 'archived']);
        $this->assertSame('archived', $league->fresh()->status);
        $this->assertSame('archived', $season->fresh()->status);
        $this->assertNotNull($tournament->fresh());
    }

    public function test_explicit_league_permission_does_not_open_seasons_or_tournaments(): void
    {
        $superadmin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $manager = $this->user(UserSystemRoleEnum::MANAGER);

        $this->login($manager);
        $this->assertFalse(LeagueResource::canViewAny());
        $this->assertFalse(LeagueSeasonResource::canViewAny());
        $this->assertFalse(TournamentResource::canViewAny());

        app(BackofficePermissionManager::class)->sync(
            $superadmin,
            $manager,
            [Permission::BACKOFFICE_ACCESS->value, Permission::BACKOFFICE_LEAGUE_MANAGE->value],
            overrideInherited: true,
        );

        $this->assertTrue(LeagueResource::canCreate());
        $this->assertFalse(LeagueSeasonResource::canCreate());
        $this->assertFalse(TournamentResource::canCreate());
        $this->get(LeagueResource::getUrl('index'))->assertOk();
        $this->get(LeagueSeasonResource::getUrl('index'))->assertForbidden();
        $this->get(TournamentResource::getUrl('index'))->assertForbidden();

        $league = app(CompetitionCatalogEditor::class)->createLeague(['name' => 'Делегированная лига']);
        $this->assertInstanceOf(League::class, $league);
        $this->expectException(AuthorizationException::class);
        app(CompetitionCatalogEditor::class)->createSeason(['league_id' => $league->id, 'name' => 'Нельзя']);
    }

    public function test_regular_user_cannot_write_even_when_editor_is_called_directly(): void
    {
        $this->login($this->user(UserSystemRoleEnum::USER));
        $this->expectException(AuthorizationException::class);
        app(CompetitionCatalogEditor::class)->createLeague(['name' => 'Запрещённая лига']);
    }
}
