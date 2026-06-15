<?php

namespace Tests\Feature\Identity;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Exceptions\UserCannotBeChangedException;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_account_cannot_be_confirmed_without_verified_primary_contact(): void
    {
        $user = $this->makeUser(UserStatusEnum::UNCONFIRMED);

        $this->expectException(UserCannotBeChangedException::class);
        $this->expectExceptionMessage('Для подтверждения аккаунта нужен подтвержденный основной контакт.');

        $user->confirmAccount();
    }

    public function test_user_account_can_be_confirmed_with_verified_primary_contact(): void
    {
        $user = $this->makeUser(UserStatusEnum::UNCONFIRMED);
        $this->addVerifiedPrimaryEmail($user);

        $user->confirmAccount();

        $this->assertSame(UserStatusEnum::CONFIRMED, $user->refresh()->status);
    }

    public function test_only_unconfirmed_account_can_be_confirmed(): void
    {
        $user = $this->makeUser(UserStatusEnum::BLOCKED);
        $this->addVerifiedPrimaryEmail($user);

        $this->expectException(UserCannotBeChangedException::class);
        $this->expectExceptionMessage('Подтвердить можно только неподтвержденный аккаунт.');

        $user->confirmAccount();
    }

    public function test_user_account_can_be_blocked(): void
    {
        $user = $this->makeUser(UserStatusEnum::CONFIRMED);

        $user->blockAccount();

        $this->assertSame(UserStatusEnum::BLOCKED, $user->refresh()->status);
    }

    public function test_removed_account_cannot_be_blocked(): void
    {
        $user = $this->makeUser(UserStatusEnum::REMOVED);

        $this->expectException(UserCannotBeChangedException::class);
        $this->expectExceptionMessage('Удаленный аккаунт нельзя заблокировать.');

        $user->blockAccount();
    }

    private function makeUser(UserStatusEnum $status): User
    {
        return User::factory()->create([
            'username' => fake()->unique()->userName(),
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => $status,
        ]);
    }

    private function addVerifiedPrimaryEmail(User $user): void
    {
        $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => fake()->unique()->safeEmail(),
            'is_primary' => true,
            'is_public' => false,
            'verified_at' => now(),
        ]);
    }
}
