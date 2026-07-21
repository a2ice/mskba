<?php

namespace Tests\Feature\Auth;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContactLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_verified_email_regardless_of_case(): void
    {
        $user = $this->user();
        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'Player@Example.COM',
            'is_primary' => true,
            'verified_at' => now(),
        ]);

        $this->assertSame('player@example.com', $contact->value);

        $this->post(route('auth.login'), [
            'login' => 'PLAYER@EXAMPLE.COM',
            'password' => 'Strong1!',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_unverified_contact_cannot_be_used_for_login(): void
    {
        $user = $this->user();
        $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'unverified@example.com',
            'is_primary' => true,
            'verified_at' => null,
        ]);

        $this->post(route('auth.login'), [
            'login' => 'unverified@example.com',
            'password' => 'Strong1!',
        ])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_user_email_is_unique_among_users_but_may_match_a_venue_email(): void
    {
        $firstUser = $this->user('first_user');
        $secondUser = $this->user('second_user');

        $userEmail = $firstUser->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'shared@example.com',
            'verified_at' => now(),
        ]);

        Contact::query()->create([
            'contactable_type' => 'venue',
            'contactable_id' => 999,
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'SHARED@example.com',
        ]);

        $userEmail->delete();

        $this->actingAs($secondUser)
            ->post(route('account.contacts.store'), [
                'type' => ContactTypeEnum::EMAIL->value,
                'value' => 'Shared@Example.com',
            ])
            ->assertRedirect(route('account.contacts'))
            ->assertSessionHas('error', 'Этот email уже используется другим пользователем.');

        $this->assertDatabaseHas('contacts', [
            'contactable_type' => 'venue',
            'contactable_id' => 999,
            'value' => 'shared@example.com',
            'user_email_key' => null,
        ]);
        $this->assertSame(1, Contact::query()->withTrashed()->where('user_email_key', 'shared@example.com')->count());
    }

    public function test_telegram_mini_app_user_can_login_with_verified_current_username(): void
    {
        $user = $this->user();
        $user->contacts()->create([
            'type' => ContactTypeEnum::TELEGRAM,
            'value' => '123456789',
            'verified_at' => now(),
            'meta' => [
                'source' => 'telegram_mini_app',
                'telegram_user_id' => 123456789,
                'username' => 'court_player',
            ],
        ]);

        $this->post(route('auth.login'), [
            'login' => '@COURT_PLAYER',
            'password' => 'Strong1!',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_database_rejects_duplicate_user_email_if_application_guard_is_bypassed(): void
    {
        $firstUser = $this->user('database_first_user');
        $secondUser = $this->user('database_second_user');

        $firstUser->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'database@example.com',
        ]);

        $this->expectException(QueryException::class);

        $secondUser->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'DATABASE@example.com',
        ]);
    }

    private function user(string $username = 'contact_user'): User
    {
        return User::factory()->create([
            'username' => $username,
            'password' => 'Strong1!',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
    }
}
