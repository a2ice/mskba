<?php

namespace Tests\Feature\Leagues;

use App\Modules\Contract\Application\Services\EntityContractManager;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum as Scope;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\League\Domain\Models\League;
use App\Modules\League\Domain\Models\LeagueSeason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicLeaguePagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('themes.active', 'mskba_app');
        view()->replaceNamespace('theme', [resource_path('themes/mskba_app/views')]);
    }

    private function league(string $name, string $status = 'active'): League
    {
        static $sequence = 0;
        $sequence++;

        return League::query()->create([
            'name' => $name,
            'alias' => 'league-'.$sequence,
            'status' => $status,
            'owner_user_id' => User::factory()->create()->id,
        ]);
    }

    private function season(League $league, string $name, string $status = 'active'): LeagueSeason
    {
        return LeagueSeason::query()->create([
            'league_id' => $league->id,
            'name' => $name,
            'status' => $status,
        ]);
    }

    public function test_guest_catalog_and_detail_hide_drafts_but_allow_archives(): void
    {
        $visible = $this->league('Видимая лига');
        $draft = $this->league('Скрытая лига', 'draft');
        $archived = $this->league('Историческая лига', 'archived');

        $this->get(route('leagues.index'))->assertOk()
            ->assertSee('Видимая лига')
            ->assertSee('Историческая лига')
            ->assertDontSee('Скрытая лига');
        $this->get(route('leagues.show', $visible->alias))->assertOk()->assertSee($visible->name);
        $this->get(route('leagues.show', $archived->alias))->assertOk();
        $this->get(route('leagues.show', $draft->alias))->assertNotFound();
    }

    public function test_catalog_search_status_and_season_filters(): void
    {
        $one = $this->league('Первая лига');
        $two = $this->league('Вторая лига', 'archived');
        $season = $this->season($one, 'Сезон один');

        $this->get(route('leagues.index', ['q' => 'Первая']))->assertOk()
            ->assertSee($one->name)->assertDontSee($two->name);
        $this->get(route('leagues.index', ['status' => 'archived']))->assertOk()
            ->assertSee($two->name)->assertDontSee('href="'.route('leagues.show', $one->alias).'"', false);
        $this->get(route('leagues.index', ['season' => $season->id]))->assertOk()
            ->assertSee($one->name)->assertDontSee($two->name);
    }

    public function test_nested_season_cannot_be_viewed_for_another_league_or_as_draft(): void
    {
        $league = $this->league('Первый клуб');
        $other = $this->league('Другой клуб');
        $season = $this->season($league, 'Сезон 2026');
        $draft = $this->season($league, 'Неопубликованный сезон', 'draft');

        $this->get(route('leagues.seasons.show', [$league->alias, $season->id]))
            ->assertOk()->assertSee('Сезон 2026')->assertDontSee('Неопубликованный сезон');
        $this->get(route('leagues.seasons.show', [$other->alias, $season->id]))->assertNotFound();
        $this->get(route('leagues.seasons.show', [$league->alias, $draft->id]))->assertNotFound();
        $this->get(route('leagues.show', $league->alias))
            ->assertOk()->assertSee('Сезон 2026')->assertDontSee('Неопубликованный сезон');
    }

    public function test_owner_has_banner_editor_links_without_exposing_editing_to_guests(): void
    {
        $league = $this->league('Лига для оформления');
        $season = $this->season($league, 'Осенний сезон');
        $owner = User::query()->findOrFail($league->owner_user_id);

        $this->get(route('leagues.show', $league->alias))
            ->assertOk()
            ->assertDontSee('Изменить баннер');
        $this->get(route('leagues.seasons.show', [$league->alias, $season->id]))
            ->assertOk()
            ->assertDontSee('Изменить баннер');

        $this->actingAs($owner)->get(route('leagues.show', $league->alias))
            ->assertOk()
            ->assertDontSee('Изменить баннер')
            ->assertDontSee('Редактировать лигу');
        $this->get(route('leagues.seasons.show', [$league->alias, $season->id]))
            ->assertOk()
            ->assertDontSee('Изменить баннер')
            ->assertDontSee('Редактировать сезон');

        $this->get(route('account.leagues.banner', $league->id))
            ->assertOk()
            ->assertSee('Лига и сезоны')
            ->assertSee('data-entity-banner-editor', false)
            ->assertSee('data-banner-delete-confirm', false)
            ->assertSee('aria-current="page"', false);
        $this->get(route('account.leagues.seasons.banner', [$league->id, $season->id]))
            ->assertOk()
            ->assertSee('Осенний сезон')
            ->assertSee('data-banner-canvas', false);

        $otherUser = User::factory()->create();
        $this->actingAs($otherUser)->get(route('account.leagues.banner', $league->id))
            ->assertForbidden();
    }

    public function test_contract_owner_uses_only_pencil_and_edits_banner_with_logo(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $league = League::query()->create([
            'name' => 'Новая лига', 'alias' => 'new-league', 'status' => 'active',
            'owner_user_id' => $owner->id,
        ]);
        $season = $this->season($league, 'Сезон 2026');
        $contracts = app(EntityContractManager::class);
        $contracts->grantOwner(Scope::LEAGUE, $league->id, $owner, $owner);
        $contracts->grantOwner(Scope::SEASON, $season->id, $owner, $owner);

        $this->get(route('leagues.show', $league->alias))
            ->assertOk()->assertDontSee('app-entity-edit-link');
        $this->actingAs($owner)->get(route('leagues.show', $league->alias))
            ->assertOk()
            ->assertSee('app-entity-edit-link')
            ->assertSee(route('leagues.manage', $league->id))
            ->assertDontSee('Изменить баннер')
            ->assertDontSee('Редактировать лигу');

        $this->get(route('leagues.seasons.show', [$league->alias, $season->id]))
            ->assertOk()
            ->assertSee('app-entity-edit-link')
            ->assertSee(route('leagues.seasons.manage', [$league->id, $season->id]))
            ->assertDontSee('Изменить баннер')
            ->assertDontSee('Редактировать сезон');

        $this->get(route('leagues.manage', $league->id))
            ->assertOk()
            ->assertSee('name="logo"', false)
            ->assertSee('data-entity-banner-editor', false);
        $this->get(route('leagues.seasons.manage', [$league->id, $season->id]))
            ->assertOk()->assertSee('data-entity-banner-editor', false);

        $this->put(route('leagues.update', $league->id), [
            'name' => 'Новая лига',
            'description' => 'Описание',
            'logo' => \Illuminate\Http\UploadedFile::fake()->image('league.png', 400, 400),
        ])->assertRedirect();

        $this->assertNotNull($league->fresh()->logo_path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($league->fresh()->logo_path);

        $this->actingAs(User::factory()->create(['status' => UserStatusEnum::CONFIRMED]))
            ->get(route('leagues.manage', $league->id))->assertForbidden();
    }

    public function test_league_placeholders_and_banner_editor_use_accessible_shared_controls(): void
    {
        $league = $this->league('Лига с иконкой');
        $season = $this->season($league, 'Осенний сезон');

        $this->get(route('leagues.index'))->assertOk()
            ->assertSee('<use href="#ball"></use>', false);
        $this->get(route('leagues.show', $league->alias))->assertOk()
            ->assertSee('<use href="#ball"></use>', false);
        $this->get(route('leagues.seasons.show', [$league->alias, $season->id]))->assertOk()
            ->assertSee('<use href="#ball"></use>', false);

        $this->actingAs(User::query()->findOrFail($league->owner_user_id))
            ->get(route('account.leagues.banner', $league->id))
            ->assertOk()
            ->assertSee('data-banner-dropzone', false)
            ->assertSee('tabindex="0"', false)
            ->assertSee('Масштаб');
    }

    public function test_draft_league_does_not_expose_active_season(): void
    {
        $league = $this->league('Черновик лиги', 'draft');
        $season = $this->season($league, 'Открытый сезон');
        $this->get(route('leagues.seasons.show', [$league->alias, $season->id]))->assertNotFound();
        $this->get(route('leagues.index', ['season' => $season->id]))->assertOk()->assertDontSee($league->name);
    }
}
