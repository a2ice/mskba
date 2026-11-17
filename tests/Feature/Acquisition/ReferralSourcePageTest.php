<?php

namespace Tests\Feature\Acquisition;

use App\Modules\Acquisition\Domain\Models\ReferralAttribution;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ReferralSourcePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_referral_source_page_survives_registration_and_discards_query_parameters(): void
    {
        $inviter = User::factory()->create([
            'username' => 'source_page_inviter',
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->get(route('referral.entry', [
            'username' => $inviter->username,
            'source_page' => '/account/player/avatar?private=not-stored',
        ]))->assertRedirect(route('acquisition.join'));

        $this->assertSame('/account/player/avatar', session('referral.pending.source_page'));

        $newUser = User::factory()->create([
            'username' => 'source_page_new_user',
            'password' => Hash::make('Strong1!'),
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->post(route('auth.login'), [
            'login' => $newUser->username,
            'password' => 'Strong1!',
        ])->assertRedirect();

        $attribution = ReferralAttribution::query()
            ->where('referred_user_id', $newUser->id)
            ->sole();

        $this->assertSame($inviter->id, $attribution->referrer_user_id);
        $this->assertSame('/account/player/avatar', $attribution->source_page);
    }

    public function test_source_page_is_attached_to_a_newly_registered_account(): void
    {
        $inviter = User::factory()->create([
            'username' => 'registration_page_inviter',
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->get(route('referral.entry', [
            'username' => $inviter->username,
            'source_page' => '/account/profile/player',
        ]))->assertRedirect(route('acquisition.join'));

        $this->post(route('auth.register'), [
            'username' => 'registered_from_invitation',
            'password' => 'Strong1!Password',
            'password_confirmation' => 'Strong1!Password',
            'privacy_consent' => '1',
        ])->assertRedirect();

        $newUser = User::query()->where('username', 'registered_from_invitation')->firstOrFail();
        $attribution = ReferralAttribution::query()
            ->where('referred_user_id', $newUser->id)
            ->sole();

        $this->assertSame($inviter->id, $attribution->referrer_user_id);
        $this->assertSame('/account/profile/player', $attribution->source_page);
    }

    public function test_old_referral_links_and_external_source_pages_remain_safe(): void
    {
        $inviter = User::factory()->create([
            'username' => 'legacy_source_page_inviter',
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->get(route('referral.entry', [
            'username' => $inviter->username,
            'source_page' => 'https://outside.example/page',
        ]))->assertRedirect(route('acquisition.join'));
        $this->assertNull(session('referral.pending.source_page'));

        // A preexisting /r/{username} link has no source_page and still works.
        $this->get(route('referral.entry', ['username' => $inviter->username]))
            ->assertRedirect(route('acquisition.join'));

        $newUser = User::factory()->create([
            'username' => 'legacy_source_page_new_user',
            'password' => Hash::make('Strong1!'),
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->post(route('auth.login'), [
            'login' => $newUser->username,
            'password' => 'Strong1!',
        ])->assertRedirect();

        $attribution = ReferralAttribution::query()
            ->where('referred_user_id', $newUser->id)
            ->sole();

        $this->assertSame($inviter->id, $attribution->referrer_user_id);
        $this->assertNull($attribution->source_page);
    }
}
