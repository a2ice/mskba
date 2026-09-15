<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AccountNicknameTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_set_change_and_clear_nickname_via_json(): void
    {
        $user = User::factory()->create(['username' => 'tg_490285685']);

        $this->actingAs($user)->patchJson(route('account.nickname.update'), ['nickname' => 'CourtKing_7'])
            ->assertOk()
            ->assertJsonPath('nickname', 'courtking_7')
            ->assertJsonPath('public_url', route('users.show', ['user' => 'courtking_7']));
        $this->assertSame('courtking_7', $user->fresh()->nickname);

        $this->actingAs($user)->patchJson(route('account.nickname.update'), ['nickname' => 'new_name'])
            ->assertOk()->assertJsonPath('nickname', 'new_name');
        $this->assertSame('new_name', $user->fresh()->nickname);

        $this->actingAs($user)->patchJson(route('account.nickname.update'), ['nickname' => ''])
            ->assertOk()->assertJsonPath('nickname', null)
            ->assertJsonPath('public_url', route('users.show', ['user' => 'tg_490285685']));
        $this->assertNull($user->fresh()->nickname);
    }

    public function test_nickname_validation_and_url_collisions_are_rejected(): void
    {
        $user = User::factory()->create(['username' => 'owner_login']);
        User::factory()->create(['username' => 'busy_login', 'nickname' => 'busy_nick']);

        foreach (['_bad', 'ab', 'яигрок', 'bad-name'] as $invalid) {
            $this->actingAs($user)->patchJson(route('account.nickname.update'), ['nickname' => $invalid])
                ->assertUnprocessable()->assertJsonValidationErrors('nickname');
        }

        foreach (['busy_nick', 'busy_login'] as $busy) {
            $this->actingAs($user)->patchJson(route('account.nickname.update'), ['nickname' => $busy])
                ->assertUnprocessable()->assertJsonValidationErrors('nickname');
        }
    }

    public function test_nickname_becomes_canonical_public_profile_identifier(): void
    {
        $user = User::factory()->create(['username' => 'legacy_login', 'nickname' => 'street_guard']);

        $this->get('/users/legacy_login')->assertMovedPermanently()->assertRedirect('/users/street_guard');
        $this->get('/users/street_guard')->assertOk();
    }
}
