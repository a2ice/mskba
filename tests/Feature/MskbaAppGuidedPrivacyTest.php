<?php

namespace Tests\Feature;

use App\Modules\Identity\Application\Services\PublicUserProfileService;
use App\Modules\Identity\Application\Services\SearchDiscoverableUsers;
use App\Modules\Identity\Application\Services\UserPrivacyAccessService;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserConsent;
use App\Modules\Identity\Domain\Models\UserPrivacySetting;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class MskbaAppGuidedPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private string $previousTheme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousTheme = config('themes.active');
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    protected function tearDown(): void
    {
        config()->set('themes.active', $this->previousTheme);
        View::replaceNamespace('theme', resource_path('themes/'.$this->previousTheme.'/views'));
        app()->forgetInstance(ThemeResolver::class);

        parent::tearDown();
    }

    private function pendingUser(): User
    {
        return User::factory()->create([
            'username' => 'guided_privacy_user',
            'personal_data_distribution_required_at' => now(),
        ]);
    }

    public function test_onboarding_has_no_account_sidebar_but_shows_shaking_avatar_notice_and_toast(): void
    {
        $user = $this->pendingUser();

        $this->actingAs($user)->get(route('account.privacy.distribution'))
            ->assertOk()
            ->assertSee('data-privacy-onboarding-shell', false)
            ->assertDontSee('class="app-account-layout__aside"', false)
            ->assertSee('app-header-account--setup-pending')
            ->assertSee('data-privacy-reminder-toast', false)
            ->assertSee('Остался последний шаг регистрации')
            ->assertSee('Видимость профиля')
            ->assertSee('Видимость в поиске')
            ->assertSee('data-privacy-profile-children', false)
            ->assertSee('data-privacy-search-children', false);

        $this->get(route('welcome'))->assertSee('app-header-account--setup-pending');
    }

    public function test_hierarchy_prevents_public_children_when_profile_parent_is_closed(): void
    {
        $user = $this->pendingUser();

        $this->actingAs($user)
            ->put(route('account.privacy.distribution.update'), [
                'action' => 'save',
                'privacy_hierarchy' => '1',
                'public' => [
                    'avatar' => '1',
                    'contacts' => '1',
                    'profile_gender' => '1',
                    'profile_age' => '1',
                ],
            ])
            ->assertRedirect(route('account'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('user_privacy_settings', [
            'user_id' => $user->id,
            'type' => 'avatar',
            'visibility' => 'nobody',
        ]);
        $this->assertDatabaseHas('user_privacy_settings', [
            'user_id' => $user->id,
            'type' => 'profile_gender',
            'visibility' => 'nobody',
        ]);
        $this->assertDatabaseHas('user_privacy_settings', [
            'user_id' => $user->id,
            'type' => 'profile_age',
            'visibility' => 'nobody',
        ]);
        $this->assertDatabaseMissing('user_consents', [
            'user_id' => $user->id,
            'type' => UserConsent::TYPE_PERSONAL_DATA_DISTRIBUTION,
        ]);
    }

    public function test_publishing_profile_demographics_requires_separate_consent(): void
    {
        $user = $this->pendingUser();

        $payload = [
            'action' => 'save',
            'privacy_hierarchy' => '1',
            'public' => [
                'profile' => '1',
                'profile_gender' => '1',
                'profile_age' => '1',
            ],
        ];

        $this->actingAs($user)
            ->put(route('account.privacy.distribution.update'), $payload)
            ->assertSessionHasErrors('distribution_consent');

        $this->assertNull($user->fresh()->personal_data_distribution_setup_completed_at);

        $this->put(route('account.privacy.distribution.update'), [
            ...$payload,
            'distribution_consent' => '1',
        ])->assertRedirect(route('account'));

        $this->assertDatabaseHas('user_privacy_settings', [
            'user_id' => $user->id,
            'type' => 'profile_gender',
            'visibility' => 'everyone',
        ]);
        $this->assertDatabaseHas('user_privacy_settings', [
            'user_id' => $user->id,
            'type' => 'profile_age',
            'visibility' => 'everyone',
        ]);

        $viewer = User::factory()->create();
        $this->assertTrue(app(UserPrivacyAccessService::class)->allows(
            $user->fresh(),
            $viewer,
            UserPrivacySettingTypeEnum::PROFILE_GENDER,
        ));

        $consent = $user->consents()->where('type', UserConsent::TYPE_PERSONAL_DATA_DISTRIBUTION)->sole();
        $this->assertContains('profile_gender', $consent->payload['allowed_types']);
        $this->assertContains('profile_age', $consent->payload['allowed_types']);
    }

    public function test_search_and_invitation_controls_are_persisted_atomically(): void
    {
        $user = $this->pendingUser();

        $this->actingAs($user)->put(route('account.privacy.distribution.update'), [
            'action' => 'save',
            'privacy_hierarchy' => '1',
            'public' => ['profile' => '1'],
            'distribution_consent' => '1',
            'privacy_options' => [
                'discoverability' => 'everyone',
                'messages' => 'everyone',
                'group_invitations' => 'everyone',
            ],
        ])->assertRedirect(route('account'));

        foreach (['discoverability', 'messages', 'group_invitations'] as $type) {
            $this->assertDatabaseHas('user_privacy_settings', [
                'user_id' => $user->id,
                'type' => $type,
                'visibility' => 'everyone',
            ]);
        }

        $this->assertDatabaseHas('user_consents', [
            'user_id' => $user->id,
            'type' => UserConsent::TYPE_PERSONAL_DATA_DISTRIBUTION,
        ]);
    }

    public function test_closed_profile_forces_search_and_invitations_closed_even_if_post_is_forged(): void
    {
        $user = $this->pendingUser();

        $this->actingAs($user)->put(route('account.privacy.distribution.update'), [
            'action' => 'save',
            'privacy_hierarchy' => '1',
            'privacy_options' => [
                'discoverability' => 'everyone',
                'messages' => 'everyone',
                'group_invitations' => 'everyone',
            ],
        ])->assertRedirect(route('account'))->assertSessionHasNoErrors();

        foreach (['discoverability', 'messages', 'group_invitations'] as $type) {
            $this->assertDatabaseHas('user_privacy_settings', [
                'user_id' => $user->id,
                'type' => $type,
                'visibility' => 'nobody',
            ]);
        }
    }

    public function test_closed_player_page_cannot_publish_characteristics_even_if_post_is_forged(): void
    {
        $user = $this->pendingUser();

        $this->actingAs($user)->put(route('account.privacy.distribution.update'), [
            'action' => 'save',
            'privacy_hierarchy' => '1',
            'public' => [
                'profile' => '1',
                'player_characteristics' => '1',
                'player_games' => '1',
            ],
            'distribution_consent' => '1',
        ])->assertRedirect(route('account'));

        foreach (['player_characteristics', 'player_games'] as $type) {
            $this->assertDatabaseHas('user_privacy_settings', [
                'user_id' => $user->id,
                'type' => $type,
                'visibility' => 'nobody',
            ]);
        }
    }

    public function test_disabled_search_disables_nested_access_even_if_client_posts_everyone(): void
    {
        $user = $this->pendingUser();

        $this->actingAs($user)->put(route('account.privacy.distribution.update'), [
            'action' => 'save',
            'privacy_hierarchy' => '1',
            'public' => ['profile' => '1'],
            'distribution_consent' => '1',
            'privacy_options' => [
                'discoverability' => 'nobody',
                'messages' => 'everyone',
                'group_invitations' => 'everyone',
            ],
        ])->assertRedirect(route('account'));

        foreach (['discoverability', 'messages', 'group_invitations'] as $type) {
            $this->assertDatabaseHas('user_privacy_settings', [
                'user_id' => $user->id,
                'type' => $type,
                'visibility' => 'nobody',
            ]);
        }
    }

    public function test_public_profile_shows_only_explicitly_approved_demographics(): void
    {
        $user = $this->pendingUser();
        $user->createProfile([
            'gender' => 'male',
            'birth_date' => now()->subYears(27)->toDateString(),
        ]);

        $this->actingAs($user)->put(route('account.privacy.distribution.update'), [
            'action' => 'save',
            'privacy_hierarchy' => '1',
            'public' => ['profile' => '1', 'profile_gender' => '1'],
            'distribution_consent' => '1',
        ])->assertRedirect(route('account'));

        $data = app(PublicUserProfileService::class)
            ->page($user->fresh(), null, null);
        $this->assertSame(['gender' => 'Мужской'], $data['demographics']);
        $this->assertArrayNotHasKey('birth_date', $data);
    }

    public function test_search_and_direct_lookup_cannot_expose_a_closed_profile(): void
    {
        $viewer = User::factory()->create(['username' => 'privacy_search_viewer']);
        $subject = User::factory()->create([
            'username' => 'privacy_search_target',
            'personal_data_distribution_required_at' => null,
        ]);
        $search = app(SearchDiscoverableUsers::class);
        $access = app(UserPrivacyAccessService::class);

        UserPrivacySetting::query()->create([
            'user_id' => $subject->id,
            'type' => UserPrivacySettingTypeEnum::DISCOVERABILITY,
            'visibility' => UserPrivacyVisibilityEnum::EVERYONE,
        ]);
        $profile = UserPrivacySetting::query()->create([
            'user_id' => $subject->id,
            'type' => UserPrivacySettingTypeEnum::PROFILE,
            'visibility' => UserPrivacyVisibilityEnum::NOBODY,
        ]);

        $this->assertFalse($access->allows($subject, $viewer, UserPrivacySettingTypeEnum::DISCOVERABILITY));
        $this->assertTrue($search->handle($viewer, 'privacy_search_target')->isEmpty());
        $this->assertNull($search->findVisibleById($viewer, $subject->id));

        $profile->update(['visibility' => UserPrivacyVisibilityEnum::EVERYONE]);
        $this->assertTrue($access->allows($subject, $viewer, UserPrivacySettingTypeEnum::DISCOVERABILITY));
        $this->assertSame([$subject->id], $search->handle($viewer, 'privacy_search_target')->pluck('id')->all());
        $this->assertSame($subject->id, $search->findVisibleById($viewer, $subject->id)?->id);
    }

    public function test_user_is_not_discoverable_before_completing_the_setup(): void
    {
        $pending = $this->pendingUser();
        $viewer = User::factory()->create();

        $this->assertFalse(app(UserPrivacyAccessService::class)->allows(
            $pending,
            $viewer,
            UserPrivacySettingTypeEnum::DISCOVERABILITY,
        ));
    }
}
