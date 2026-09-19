<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DeleteOwnAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_page_exposes_confirmed_soft_delete_action(): void
    {
        $user = User::factory()->create();
        $user->createProfile([]);

        $this->actingAs($user)
            ->get(route('account'))
            ->assertOk()
            ->assertSee(route('account.destroy'), false)
            ->assertSee('Удалить аккаунт')
            ->assertSee("onsubmit=\"return confirm('вы уверены что хотите удалить аккаунт')\"", false);
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
}
