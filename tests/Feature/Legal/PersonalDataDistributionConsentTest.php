<?php

namespace Tests\Feature\Legal;

use App\Modules\Identity\Application\Services\UserPrivacyAccessService;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PersonalDataDistributionConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_distribution_consent_document_is_publicly_available(): void
    {
        $this->get(route('personal-data.distribution-consent'))
            ->assertOk()
            ->assertSee('разрешённых для распространения')
            ->assertSee(config('legal.personal_data_distribution_consent_version'));
    }

    public function test_public_selection_requires_separate_consent(): void
    {
        $user = User::factory()->create(['personal_data_distribution_required_at' => now()]);

        $this->actingAs($user)
            ->put(route('account.privacy.distribution.update'), [
                'action' => 'save',
                'public' => ['profile' => '1'],
            ])
            ->assertSessionHasErrors('distribution_consent');

        $this->assertDatabaseMissing('user_consents', [
            'user_id' => $user->id,
            'type' => UserConsent::TYPE_PERSONAL_DATA_DISTRIBUTION,
        ]);
    }

    public function test_selected_categories_are_saved_with_evidence_and_enforced(): void
    {
        $user = User::factory()->create([
            'username' => 'public_choice',
            'personal_data_distribution_required_at' => now(),
        ]);

        $this->actingAs($user)
            ->put(route('account.privacy.distribution.update'), [
                'action' => 'save',
                'public' => [
                    'profile' => '1',
                    'avatar' => '1',
                    'player_games' => '1',
                ],
                'distribution_consent' => '1',
            ])
            ->assertRedirect(route('account'));

        $user->refresh();
        $this->assertNotNull($user->personal_data_distribution_setup_completed_at);

        $consent = $user->consents()
            ->where('type', UserConsent::TYPE_PERSONAL_DATA_DISTRIBUTION)
            ->whereNull('revoked_at')
            ->sole();

        $this->assertSame('public_data_setup', $consent->source);
        $this->assertSame(['profile', 'avatar', 'player_games'], $consent->payload['allowed_types']);

        $this->assertDatabaseHas('user_privacy_settings', [
            'user_id' => $user->id,
            'type' => 'profile',
            'visibility' => UserPrivacyVisibilityEnum::EVERYONE->value,
        ]);
        $this->assertDatabaseHas('user_privacy_settings', [
            'user_id' => $user->id,
            'type' => 'player_teams',
            'visibility' => UserPrivacyVisibilityEnum::NOBODY->value,
        ]);

        $privacy = app(UserPrivacyAccessService::class);
        $this->assertTrue($privacy->allows($user, null, UserPrivacySettingTypeEnum::PROFILE));
        $this->assertFalse($privacy->allows($user, null, UserPrivacySettingTypeEnum::PLAYER_TEAMS));
    }

    public function test_private_choice_completes_setup_without_distribution_consent(): void
    {
        $user = User::factory()->create(['personal_data_distribution_required_at' => now()]);

        $this->actingAs($user)
            ->put(route('account.privacy.distribution.update'), ['action' => 'private'])
            ->assertRedirect(route('account'));

        $this->assertNotNull($user->fresh()->personal_data_distribution_setup_completed_at);
        $this->assertDatabaseMissing('user_consents', [
            'user_id' => $user->id,
            'type' => UserConsent::TYPE_PERSONAL_DATA_DISTRIBUTION,
        ]);
        $this->assertDatabaseHas('user_privacy_settings', [
            'user_id' => $user->id,
            'type' => 'profile',
            'visibility' => UserPrivacyVisibilityEnum::NOBODY->value,
        ]);
    }
}
