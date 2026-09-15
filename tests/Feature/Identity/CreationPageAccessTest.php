<?php

namespace Tests\Feature\Identity;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class CreationPageAccessTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantCreationPermissionsToTestActors = false;

    public function test_guest_can_open_each_creation_page_but_cannot_submit_or_use_private_pickers(): void
    {
        config(['features.sports_sections.enabled' => true]);
        foreach (['venues', 'events', 'teams', 'tournaments', 'coordination', 'account.sports-sections'] as $resource) {
            $this->get(route($resource.'.create'))->assertOk()
                ->assertSee('Условия создания')
                ->assertSee('name="login"', false)
                ->assertDontSee('action="'.route($resource.'.store').'"', false);
            $this->postJson(route($resource.'.store'), [])->assertUnauthorized();
        }
        foreach (['events.wizard.teams', 'events.wizard.venues', 'teams.name-suggestion'] as $route) {
            $this->getJson(route($route))->assertUnauthorized();
        }
    }

    public function test_guest_login_returns_to_original_creation_url_with_booking_parameters(): void
    {
        Queue::fake();
        $target = route('events.wizard', ['type' => 'training', 'venue_id' => 12, 'venue_court_id' => 34]);
        $this->get($target)->assertOk()->assertSessionHas('url.intended', $target);
        User::factory()->create(['username' => 'creation_return', 'password' => 'password', 'status' => UserStatusEnum::CONFIRMED]);
        $this->post(route('auth.login'), ['login' => 'creation_return', 'password' => 'password'])
            ->assertRedirect($target)->assertSessionMissing('url.intended');
    }

    public function test_reloading_after_verifying_contact_opens_form_and_preserves_query(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);
        $target = route('events.wizard', ['type' => 'training', 'venue_id' => 12, 'venue_court_id' => 34]);
        $this->actingAs($user)->get($target)->assertOk()
            ->assertSee('Подтвердить контакт')
            ->assertSee(route('faq.welcome').'#contact-confirmation', false)
            ->assertDontSee('data-event-wizard', false)
            ->assertSessionMissing('operational_permission_intent');
        $user->contacts()->create(['type' => ContactTypeEnum::EMAIL, 'value' => 'creation-recheck@example.test', 'verified_at' => now()]);
        $this->get($target)->assertOk()->assertSee('data-event-wizard', false)
            ->assertSee('value="training"', false);
    }

    public function test_team_confirmation_and_section_role_requirements_disappear_when_resolved(): void
    {
        config(['features.sports_sections.enabled' => true]);
        $user = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);
        $this->actingAs($user)->get(route('teams.create'))->assertOk()
            ->assertSee('Подтвердить аккаунт')
            ->assertSee(route('faq.welcome').'#account-confirmation', false)
            ->assertDontSee('data-team-name-form', false);
        $user->update(['status' => UserStatusEnum::CONFIRMED]);
        $this->get(route('teams.create'))->assertOk()->assertSee('data-team-name-form', false);
        $this->get(route('account.sports-sections.create'))->assertOk()
            ->assertSee('Выбрать роль тренера')
            ->assertSee(route('faq.welcome').'#participation-role', false)
            ->assertDontSee('action="'.route('account.sports-sections.store').'"', false);
        $user->participationRoles(false)->create([
            'role' => UserParticipationRoleEnum::COACH,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(), 'assigned_by' => $user->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);
        $this->get(route('account.sports-sections.create'))->assertOk()
            ->assertSee('action="'.route('account.sports-sections.store').'"', false);
    }

    public function test_section_feature_flag_and_blocked_session_remain_protected(): void
    {
        config(['features.sports_sections.enabled' => false]);
        $this->get(route('account.sports-sections.create'))->assertNotFound();
        $user = User::factory()->create(['status' => UserStatusEnum::BLOCKED]);
        $this->actingAs($user)->get(route('venues.create'))->assertForbidden();
    }
}
