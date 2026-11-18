<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DeleteOwnAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_settings_exposes_branded_soft_delete_confirmation(): void
    {
        $user = User::factory()->create();
        $user->createProfile([]);

        $this->actingAs($user)
            ->get(route('account.settings'))
            ->assertOk()
            ->assertSee(route('account.destroy'), false)
            ->assertSee('Удалить аккаунт')
            ->assertSee('data-account-delete-dialog', false)
            ->assertSee('Вы уверены, что хотите удалить аккаунт?');
    }

    public function test_user_can_soft_delete_own_account_and_is_logged_out(): void
    {
        $user = User::factory()->create();
        $user->createProfile([]);

        $this->actingAs($user)
            ->delete(route('account.destroy'))
            ->assertRedirect(route('welcome'));

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertGuest();
    }

    public function test_self_deletion_soft_deletes_the_whole_canonical_identity(): void
    {
        $canonical = User::factory()->create();
        $alias = User::factory()->create();
        $alias->forceFill(['canonical_user_id' => $canonical->id])->save();

        $this->actingAs($canonical)
            ->delete(route('account.destroy'))
            ->assertRedirect(route('welcome'));

        $this->assertSoftDeleted('users', ['id' => $canonical->id]);
        $this->assertSoftDeleted('users', ['id' => $alias->id]);
    }
    public function test_deleted_identity_has_no_tokens_or_public_profile(): void
    {
        $canonical = User::factory()->create(['username' => 'removed_member']);
        $canonical->createProfile([]);
        $alias = User::factory()->create(['canonical_user_id' => $canonical->id]);
        $canonical->createToken('phone', ['profile:read', 'account:delete']);
        $alias->createToken('tablet', ['profile:read']);
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->actingAs($canonical)->delete(route('account.destroy'))
            ->assertRedirect(route('welcome'));

        $this->assertSoftDeleted('users', ['id' => $canonical->id]);
        $this->assertSoftDeleted('users', ['id' => $alias->id]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->get(route('users.id.show', $canonical->id))->assertNotFound();
        $this->get(route('users.show', 'removed_member'))->assertNotFound();
    }
}
