<?php

namespace Tests\Feature\Identity;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationChannelEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class AccountConfirmationWizardTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(UserStatusEnum $status = UserStatusEnum::UNCONFIRMED): User
    {
        $user = User::factory()->create(['status' => $status]);
        $user->createProfile([]);

        return $user;
    }

    private function verifiedContact(User $user)
    {
        return $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => fake()->unique()->safeEmail(),
            'is_primary' => true, 'verified_at' => now(),
        ]);
    }

    private function personalData(): array
    {
        return [
            'first_name' => 'Иван', 'last_name' => 'Игроков',
            'birth_date' => '1995-05-20', 'gender' => UserGenderEnum::MALE->value,
        ];
    }

    public function test_unconfirmed_user_sees_confirmation_action_on_account_page(): void
    {
        $user = $this->makeUser();
        $this->verifiedContact($user);
        $this->actingAs($user)->get(route('account'))->assertOk()
            ->assertSee('Подтвердить аккаунт')
            ->assertSee(route('account.confirmation'), false);
    }

    public function test_confirmed_user_does_not_see_confirmation_action_on_account_page(): void
    {
        $user = $this->makeUser(UserStatusEnum::CONFIRMED);
        $this->actingAs($user)->get(route('account'))->assertOk()
            ->assertDontSee('Подтвердить аккаунт');
    }

    public function test_confirmation_page_shows_contact_and_identity_fields_not_roles_or_wizard(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->get(route('account.confirmation'))->assertOk()
            ->assertSee('Основной контакт')
            ->assertSee('Имя')
            ->assertSee('Фамилия')
            ->assertSee('Дата рождения')
            ->assertSee('Пол')
            ->assertDontSee('data-wizard-step', false)
            ->assertDontSee('id="confirmationRole"', false);
    }

    public function test_player_role_does_not_change_account_confirmation_conditions(): void
    {
        $user = $this->makeUser();
        $user->participationRoles()->create([
            'role' => UserParticipationRoleEnum::PLAYER,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(), 'assigned_by' => $user->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);
        $this->actingAs($user)->get(route('account.confirmation'))->assertOk()
            ->assertDontSee('id="confirmationRole"', false)
            ->assertSee('Дата рождения');
    }

    public function test_user_without_role_can_confirm_after_entering_all_required_identity_fields(): void
    {
        $user = $this->makeUser();
        $this->verifiedContact($user);
        $this->actingAs($user)->post(route('account.confirmation.complete'), $this->personalData())
            ->assertRedirect(route('account'));

        $this->assertSame(UserStatusEnum::CONFIRMED, $user->fresh()->status);
        $this->assertDatabaseMissing('user_participation_roles', ['user_id' => $user->id]);
        $this->assertDatabaseHas('profiles', ['user_id' => $user->id, 'first_name' => 'Иван', 'last_name' => 'Игроков']);
    }

    public function test_all_identity_fields_are_required_regardless_of_participation_role(): void
    {
        $user = $this->makeUser();
        $this->verifiedContact($user);
        $this->actingAs($user)->post(route('account.confirmation.complete'), [
            'first_name' => 'Иван',
        ])->assertSessionHasErrors(['last_name', 'birth_date', 'gender']);
        $this->assertSame(UserStatusEnum::UNCONFIRMED, $user->fresh()->status);
    }

    public function test_confirmation_requires_verified_primary_contact(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->post(route('account.confirmation.complete'), $this->personalData())
            ->assertSessionHasErrors('contact');
        $this->assertSame(UserStatusEnum::UNCONFIRMED, $user->fresh()->status);
    }

    public function test_confirmation_page_displays_verified_primary_contact(): void
    {
        $user = $this->makeUser();
        $contact = $this->verifiedContact($user);
        $this->actingAs($user)->get(route('account.confirmation'))->assertOk()
            ->assertSee('Основной контакт подтверждён')
            ->assertSee($contact->value);
    }

    public function test_confirmation_page_can_add_primary_email_contact_and_start_verification(): void
    {
        Mail::fake();
        $user = $this->makeUser();
        $this->actingAs($user)->post(route('account.confirmation.contact.store'), [
            'type' => ContactTypeEnum::EMAIL->value, 'value' => 'confirmation@example.test',
        ])->assertRedirect(route('account.confirmation'));

        $this->assertDatabaseHas('contacts', [
            'contactable_type' => 'user', 'contactable_id' => $user->id,
            'type' => ContactTypeEnum::EMAIL->value, 'value' => 'confirmation@example.test',
            'is_primary' => true, 'verified_at' => null,
        ]);
        $this->assertDatabaseHas('contact_verifications', ['status' => ContactVerificationStatusEnum::PENDING->value]);
    }

    public function test_confirmation_page_can_verify_primary_email_contact(): void
    {
        $user = $this->makeUser();
        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'pending@example.test', 'is_primary' => true,
        ]);
        $verification = $contact->verifications()->create([
            'channel' => ContactVerificationChannelEnum::EMAIL,
            'status' => ContactVerificationStatusEnum::PENDING,
            'code_hash' => bcrypt('123456'),
            'sent_to' => 'pending@example.test',
            'expires_at' => now()->addMinutes(10),
        ]);
        $this->actingAs($user)->post(route('account.confirmation.contacts.verification.confirm', $contact), [
            'code' => '123456',
        ])->assertRedirect(route('account.confirmation'));
        $this->assertNotNull($contact->fresh()->verified_at);
        $this->assertSame(ContactVerificationStatusEnum::CONFIRMED, $verification->fresh()->status);
    }
}
