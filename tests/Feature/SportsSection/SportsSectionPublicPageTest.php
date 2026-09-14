<?php

namespace Tests\Feature\SportsSection;

use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\SportsSection\Application\UseCases\CreateSportsSectionHandler;
use App\Modules\SportsSection\Application\UseCases\ManageSectionCoachHandler;
use App\Modules\SportsSection\Application\UseCases\ManageSectionTraineeHandler;
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

    public function test_public_page_shows_age_group_single_recruitment_badge_capacity_and_clear_guest_cta(): void
    {
        [$owner, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        [$player] = $this->roleUser(UserParticipationRoleEnum::PLAYER);
        $section = $this->activeSection($actor, [
            'name' => 'Школа броска',
            'accepts_trainee_requests' => true,
            'is_recruiting' => true,
            'max_trainees' => 15,
        ]);
        app(ManageSectionTraineeHandler::class)->activate($section, $player, $owner);
        DB::table('sports_sections')->where('id', $section->id)->update([
            'target_year_from' => 2010,
            'target_year_to' => 2012,
        ]);

        $this->get(route('sports-sections.show', $section))
            ->assertOk()
            ->assertSee('2010–2012 г.р.')
            ->assertSee('Идёт набор 1/15')
            ->assertDontSee('Принимает заявки 1/15')
            ->assertSee('Записаться')
            ->assertSee('data-modal-target="auth-entry-classic"', false)
            ->assertSee('Ближайшие подтверждённые занятия')
            ->assertSee('Стоимость и тарифы')
            ->assertDontSee('title="Групповой"', false);
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

    public function test_inactive_coach_membership_is_not_shown_on_public_page(): void
    {
        [$owner, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        $section = $this->activeSection($actor);
        [$coach] = $this->roleUser(UserParticipationRoleEnum::COACH);
        $coach->forceFill(['username' => 'former-section-coach'])->save();

        $handler = app(ManageSectionCoachHandler::class);
        $membership = $handler->add($section, $coach, $owner);

        $this->get(route('sports-sections.show', $section))
            ->assertOk()
            ->assertSee('former-section-coach');

        $handler->remove($section, $membership, $owner);

        $this->get(route('sports-sections.show', $section))
            ->assertOk()
            ->assertDontSee('former-section-coach');
    }

    public function test_public_responsible_coach_exposes_minimum_identity_avatar_and_preview_even_when_not_discoverable(): void
    {
        [$owner, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        $section = $this->activeSection($actor);
        [$coach] = $this->roleUser(UserParticipationRoleEnum::COACH);
        $coach->forceFill(['username' => 'private-coach'])->save();
        $profile = $coach->createProfile(['first_name' => 'Иван', 'last_name' => 'Тренеров']);
        $avatar = Media::factory()->for($profile, 'mediable')->create([
            'collection' => 'avatar',
            'path' => 'avatars/section-coach.jpg',
            'is_featured' => true,
        ]);
        $coach->privacySettings()->updateOrCreate([
            'type' => UserPrivacySettingTypeEnum::DISCOVERABILITY,
        ], [
            'visibility' => UserPrivacyVisibilityEnum::NOBODY,
        ]);
        app(ManageSectionCoachHandler::class)->add($section, $coach, $owner);

        $this->get(route('sports-sections.show', $section))
            ->assertOk()
            ->assertSee('Иван Тренеров')
            ->assertSee($avatar->publicUrl(), false)
            ->assertSee('data-modal-target="section-coach-preview-'.$coach->id.'"', false)
            ->assertSee('data-public-user-role-placeholder="coach"', false)
            ->assertSee('независимо от настройки видимости в поиске');
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

    public function test_capacity_setting_is_optional_and_cannot_be_lower_than_active_roster(): void
    {
        [$owner, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        [$player] = $this->roleUser(UserParticipationRoleEnum::PLAYER);
        $section = $this->activeSection($actor);
        app(ManageSectionTraineeHandler::class)->activate($section, $player, $owner);

        $this->actingAs($owner)
            ->patch(route('account.sports-sections.applications.settings', $section), [
                'accepts_trainee_requests' => 1,
                'is_recruiting' => 0,
                'max_trainees' => 15,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->assertSame(15, $section->fresh()->max_trainees);

        $this->patch(route('account.sports-sections.applications.settings', $section), [
            'accepts_trainee_requests' => 1,
            'is_recruiting' => 0,
            'max_trainees' => 0,
        ])->assertSessionHasErrors('max_trainees');
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
            'max_trainees' => $overrides['max_trainees'] ?? null,
        ])->save();

        return $section->refresh();
    }
}
