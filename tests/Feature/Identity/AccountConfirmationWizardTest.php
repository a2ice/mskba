<?php

namespace Tests\Feature\Identity;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationChannelEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccountConfirmationWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconfirmed_user_sees_confirmation_button_on_account_page(): void
    {
        $user = $this->makeUser(UserStatusEnum::UNCONFIRMED);
        $user->createProfile([]);
        $this->addVerifiedPrimaryEmail($user);

        $response = $this->actingAs($user)->get(route('account'));

        $response->assertOk();
        $response->assertSee('Подтвердить аккаунт');
        $response->assertSee(route('account.confirmation'), false);
    }

    public function test_confirmed_user_does_not_see_confirmation_button_on_account_page(): void
    {
        $user = $this->makeUser(UserStatusEnum::CONFIRMED);
        $user->createProfile([]);

        $response = $this->actingAs($user)->get(route('account'));

        $response->assertOk();
        $response->assertDontSee('Подтвердить аккаунт');
    }

    public function test_confirmation_wizard_shows_role_and_name_steps_for_user_without_role(): void
    {
        $user = $this->makeUser(UserStatusEnum::UNCONFIRMED);
        $user->createProfile([]);

        $response = $this->actingAs($user)->get(route('account.confirmation'));

        $response->assertOk();
        $response->assertSee('Подтвердите основной контакт');
        $response->assertSee('Выберите роль участия');
        $response->assertSee('Представьтесь, пожалуйста');
        $response->assertSee('(Н)');
        $response->assertSee('data-role-dependent="true"', false);
        $response->assertSee('data-step-key="birth_date"', false);
        $response->assertSee('data-step-key="gender"', false);
        $response->assertSee('hidden', false);
    }

    public function test_existing_player_role_starts_wizard_from_birth_date_step(): void
    {
        $user = $this->makeUser(UserStatusEnum::UNCONFIRMED);
        $user->createProfile([]);
        $this->assignRole($user, UserParticipationRoleEnum::PLAYER);

        $response = $this->actingAs($user)->get(route('account.confirmation'));

        $response->assertOk();
        $response->assertSee('Подтвердите основной контакт');
        $response->assertDontSee('data-step-key="participation_role"', false);
        $response->assertDontSee('Выберите роль участия');
        $response->assertSee('Заполните дату рождения');
        $response->assertSee('Укажите пол');
        $response->assertSee('data-step-key="birth_date"', false);
        $response->assertSee('data-step-key="gender"', false);
        $response->assertSee('data-existing-role="player"', false);
        $response->assertSee('data-existing-role-label="Игрок"', false);
    }

    public function test_existing_non_player_role_starts_wizard_from_name_step(): void
    {
        $user = $this->makeUser(UserStatusEnum::UNCONFIRMED);
        $user->createProfile([]);
        $this->assignRole($user, UserParticipationRoleEnum::REFEREE);

        $response = $this->actingAs($user)->get(route('account.confirmation'));

        $response->assertOk();
        $response->assertSee('Подтвердите основной контакт');
        $response->assertDontSee('data-step-key="participation_role"', false);
        $response->assertDontSee('data-step-key="birth_date"', false);
        $response->assertDontSee('data-step-key="gender"', false);
        $response->assertSee('Представьтесь, пожалуйста');
        $response->assertSee('data-existing-role="referee"', false);
        $response->assertSee('data-existing-role-label="Судья"', false);
    }

    public function test_required_steps_confirm_player_account(): void
    {
        $user = $this->makeUser(UserStatusEnum::UNCONFIRMED);
        $user->createProfile([]);
        $this->addVerifiedPrimaryEmail($user);

        $this->actingAs($user)->post(route('account.confirmation.complete'), [
            'role' => UserParticipationRoleEnum::PLAYER->value,
            'birth_date' => '1995-05-20',
            'gender' => UserGenderEnum::MALE->value,
            'first_name' => 'Иван',
            'last_name' => 'Игроков',
        ])->assertRedirect(route('account'));

        $this->assertDatabaseHas('user_participation_roles', [
            'user_id' => $user->id,
            'role' => UserParticipationRoleEnum::PLAYER->value,
        ]);

        $this->assertSame(UserStatusEnum::CONFIRMED, $user->refresh()->status);
        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'first_name' => 'Иван',
            'last_name' => 'Игроков',
            'gender' => UserGenderEnum::MALE->value,
        ]);
    }

    public function test_player_confirmation_requires_birth_date_and_gender_on_final_submit(): void
    {
        $user = $this->makeUser(UserStatusEnum::UNCONFIRMED);
        $user->createProfile([]);
        $this->addVerifiedPrimaryEmail($user);

        $response = $this->actingAs($user)->post(route('account.confirmation.complete'), [
            'role' => UserParticipationRoleEnum::PLAYER->value,
            'first_name' => 'Иван',
            'last_name' => 'Игроков',
        ]);

        $response->assertSessionHasErrors(['birth_date', 'gender']);
        $this->assertSame(UserStatusEnum::UNCONFIRMED, $user->refresh()->status);
    }

    public function test_non_player_role_can_be_confirmed_without_birth_date_and_gender(): void
    {
        $user = $this->makeUser(UserStatusEnum::UNCONFIRMED);
        $user->createProfile([]);
        $this->addVerifiedPrimaryEmail($user);

        $this->actingAs($user)->post(route('account.confirmation.complete'), [
            'role' => UserParticipationRoleEnum::REFEREE->value,
        ])->assertRedirect(route('account'));

        $this->assertSame(UserStatusEnum::CONFIRMED, $user->refresh()->status);
        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'first_name' => null,
            'last_name' => null,
        ]);
    }

    public function test_confirmation_requires_verified_primary_contact(): void
    {
        $user = $this->makeUser(UserStatusEnum::UNCONFIRMED);
        $user->createProfile([]);

        $response = $this->actingAs($user)->post(route('account.confirmation.complete'), [
            'role' => UserParticipationRoleEnum::REFEREE->value,
        ]);

        $response->assertSessionHasErrors(['contact']);
        $this->assertSame(UserStatusEnum::UNCONFIRMED, $user->refresh()->status);
    }

    public function test_confirmation_wizard_displays_verified_primary_contact(): void
    {
        $user = $this->makeUser(UserStatusEnum::UNCONFIRMED);
        $user->createProfile([]);
        $contact = $this->addVerifiedPrimaryEmail($user);

        $response = $this->actingAs($user)->get(route('account.confirmation'));

        $response->assertOk();
        $response->assertSee('Основной контакт подтвержден');
        $response->assertSee($contact->value);
        $response->assertSee('data-contact-completed="true"', false);
    }

    public function test_confirmation_wizard_can_add_primary_email_contact_and_start_verification(): void
    {
        Mail::fake();

        $user = $this->makeUser(UserStatusEnum::UNCONFIRMED);
        $user->createProfile([]);

        $response = $this->actingAs($user)->post(route('account.confirmation.contact.store'), [
            'type' => ContactTypeEnum::EMAIL->value,
            'value' => 'confirmation@example.test',
        ]);

        $response->assertRedirect(route('account.confirmation'));
        $this->assertDatabaseHas('contacts', [
            'contactable_type' => 'user',
            'contactable_id' => $user->id,
            'type' => ContactTypeEnum::EMAIL->value,
            'value' => 'confirmation@example.test',
            'is_primary' => true,
            'verified_at' => null,
        ]);
        $this->assertDatabaseHas('contact_verifications', [
            'status' => ContactVerificationStatusEnum::PENDING->value,
        ]);
    }

    public function test_confirmation_wizard_can_confirm_primary_contact(): void
    {
        $user = $this->makeUser(UserStatusEnum::UNCONFIRMED);
        $user->createProfile([]);
        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'pending@example.test',
            'is_primary' => true,
            'is_public' => false,
        ]);
        $verification = $contact->verifications()->create([
            'channel' => ContactVerificationChannelEnum::EMAIL,
            'status' => ContactVerificationStatusEnum::PENDING,
            'code_hash' => bcrypt('123456'),
            'sent_to' => 'pending@example.test',
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->actingAs($user)->post(route('account.confirmation.contacts.verification.confirm', $contact), [
            'code' => '123456',
        ]);

        $response->assertRedirect(route('account.confirmation'));
        $this->assertNotNull($contact->fresh()->verified_at);
        $this->assertSame(ContactVerificationStatusEnum::CONFIRMED, $verification->fresh()->status);
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

    private function assignRole(User $user, UserParticipationRoleEnum $role): void
    {
        $user->participationRoles()->create([
            'role' => $role,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(),
            'assigned_by' => $user->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);
    }

    private function addVerifiedPrimaryEmail(User $user)
    {
        return $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => fake()->unique()->safeEmail(),
            'is_primary' => true,
            'is_public' => false,
            'verified_at' => now(),
        ]);
    }
}
