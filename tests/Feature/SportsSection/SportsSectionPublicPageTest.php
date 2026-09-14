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
use App\Modules\SportsSection\Domain\Models\SportsSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SportsSectionPublicPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.sports_sections.enabled', true);
    }

    public function test_public_page_uses_section_sidebar_and_shows_age_group_and_clear_guest_cta(): void
    {
        [, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        $section = $this->activeSection($actor, [
            'name' => 'Школа броска',
            'accepts_trainee_requests' => true,
            'is_recruiting' => true,
        ]);
        DB::table('sports_sections')->where('id', $section->id)->update([
            'target_year_from' => 2010,
            'target_year_to' => 2012,
        ]);

        $this->get(route('sports-sections.show', $section))
            ->assertOk()
            ->assertSee('sports-section-public section-sidebar-layout', false)
            ->assertSee('2010–2012 г.р.')
            ->assertSee('Идёт набор')
            ->assertSee('Записаться')
            ->assertSee('data-modal-target="auth-entry-classic"', false)
            ->assertSee('Ближайшие подтверждённые занятия')
            ->assertSee('Стоимость и тарифы')
            ->assertDontSee('title="Групповой"', false)
            ->assertDontSee('data-tooltip-icon', false);
    }

    public function test_manager_gets_direct_public_management_navigation(): void
    {
        [$owner, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        $section = $this->activeSection($actor, ['accepts_trainee_requests' => true]);

        $this->actingAs($owner)
            ->get(route('sports-sections.show', $section))
            ->assertOk()
            ->assertSee('Редактировать секцию')
            ->assertSee('Заявки и набор')
            ->assertSee('Связанные команды')
            ->assertSee('Управлять');
    }

    public function test_closed_section_explicitly_shows_closed_recruitment_state(): void
    {
        [, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        $section = $this->activeSection($actor);

        $this->get(route('sports-sections.show', $section))
            ->assertOk()
            ->assertSee('Набор закрыт')
            ->assertSee('Секция сейчас не принимает новые заявки.');
    }

    public function test_recruitment_settings_persist_exact_or_range_target_years_exclusively(): void
    {
        [$owner, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        $section = $this->activeSection($actor);

        $this->actingAs($owner)
            ->patch(route('account.sports-sections.applications.settings', $section), [
                'accepts_trainee_requests' => 1,
                'is_recruiting' => 0,
                'audience_mode' => 'exact',
                'target_year' => 2011,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('sports_sections', [
            'id' => $section->id,
            'target_year' => 2011,
            'target_year_from' => null,
            'target_year_to' => null,
        ]);

        $this->patch(route('account.sports-sections.applications.settings', $section), [
            'accepts_trainee_requests' => 1,
            'is_recruiting' => 1,
            'audience_mode' => 'range',
            'target_year_from' => 2009,
            'target_year_to' => 2012,
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('sports_sections', [
            'id' => $section->id,
            'target_year' => null,
            'target_year_from' => 2009,
            'target_year_to' => 2012,
        ]);
    }

    public function test_target_year_range_rejects_inverted_boundaries(): void
    {
        [$owner, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        $section = $this->activeSection($actor);

        $this->actingAs($owner)
            ->patch(route('account.sports-sections.applications.settings', $section), [
                'accepts_trainee_requests' => 1,
                'is_recruiting' => 0,
                'audience_mode' => 'range',
                'target_year_from' => 2014,
                'target_year_to' => 2010,
            ])
            ->assertSessionHasErrors('target_year_to');
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
        $section = app(CreateSportsSectionHandler::class)->handle($actor, [
            'name' => $overrides['name'] ?? 'Секция '.fake()->unique()->numberBetween(1, 999999),
            'description' => 'Регулярные тренировки.',
            'training_mode' => 'group',
            'game_format' => 'basketball',
            'pricing_type' => 'free',
            'single_session_price_minor' => null,
            'currency' => 'RUB',
            'contact_source' => 'head_coach',
        ]);
        $section->forceFill([
            'status' => 'active',
            'accepts_trainee_requests' => (bool) ($overrides['accepts_trainee_requests'] ?? false),
            'is_recruiting' => (bool) ($overrides['is_recruiting'] ?? false),
        ])->save();

        return $section->refresh();
    }
}
