<?php

namespace Tests\Feature\Contact;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationChannelEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\Contact\Presentation\Mail\ContactVerificationCodeMail;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use App\Modules\Vk\Domain\Models\VkAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_contacts_page_consolidates_linked_provider_identities(): void
    {
        config([
            'telegram.bot_username' => 'mskba_test_bot',
            'vk.app_id' => '12345',
            'vk.redirect_uri' => 'https://mskba.test/auth/vk/callback',
        ]);

        $user = User::factory()->create();
        TelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => 123456,
            'username' => 'linked_telegram',
        ]);
        VkAccount::query()->create([
            'user_id' => $user->id,
            'vk_user_id' => '654321',
            'first_name' => 'Иван',
            'last_name' => 'Петров',
        ]);

        $this->actingAs($user)
            ->get(route('account.contacts'))
            ->assertOk()
            ->assertSee('Подтверждённые способы связи и входа')
            ->assertSee('@linked_telegram')
            ->assertSee('Иван Петров')
            ->assertDontSee('option value="telegram"', false)
            ->assertDontSee('option value="vk"', false);
    }

    public function test_legacy_provider_account_pages_redirect_to_contacts(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('account.telegram'))
            ->assertRedirect(route('account.contacts'));

        $this->actingAs($user)
            ->get(route('account.vk'))
            ->assertRedirect(route('account.contacts'));
    }

    public function test_user_can_have_contact_with_verification(): void
    {
        $user = User::factory()->create([
            'username' => 'contact_user',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'contact@example.test',
            'is_primary' => true,
            'is_public' => false,
        ]);

        $verification = $contact->verifications()->create([
            'channel' => ContactVerificationChannelEnum::EMAIL,
            'status' => ContactVerificationStatusEnum::PENDING,
            'sent_to' => 'contact@example.test',
        ]);

        $this->assertSame('user', $contact->contactable_type);
        $this->assertTrue($contact->type === ContactTypeEnum::EMAIL);
        $this->assertTrue($verification->channel === ContactVerificationChannelEnum::EMAIL);
        $this->assertDatabaseHas('contacts', [
            'contactable_type' => 'user',
            'contactable_id' => $user->id,
            'type' => ContactTypeEnum::EMAIL->value,
            'value' => 'contact@example.test',
            'is_primary' => true,
        ]);
    }

    public function test_user_can_add_account_contact(): void
    {
        $user = User::factory()->create([
            'username' => 'account_contact_user',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $response = $this->actingAs($user)->post(route('account.contacts.store'), [
            'type' => ContactTypeEnum::EMAIL->value,
            'value' => 'account-contact@example.test',
            'label' => 'Личный',
        ]);

        $response->assertRedirect(route('account.contacts'));
        $this->assertDatabaseHas('contacts', [
            'contactable_type' => 'user',
            'contactable_id' => $user->id,
            'type' => ContactTypeEnum::EMAIL->value,
            'value' => 'account-contact@example.test',
            'label' => 'Личный',
            'is_primary' => true,
            'is_public' => false,
        ]);
    }

    public function test_second_account_contact_is_not_primary(): void
    {
        $user = User::factory()->create([
            'username' => 'second_account_contact_user',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->actingAs($user)->post(route('account.contacts.store'), [
            'type' => ContactTypeEnum::EMAIL->value,
            'value' => 'first-account-contact@example.test',
        ]);

        $response = $this->actingAs($user)->post(route('account.contacts.store'), [
            'type' => ContactTypeEnum::EMAIL->value,
            'value' => 'second-account-contact@example.test',
        ]);

        $response->assertRedirect(route('account.contacts'));
        $this->assertDatabaseHas('contacts', [
            'contactable_type' => 'user',
            'contactable_id' => $user->id,
            'value' => 'first-account-contact@example.test',
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('contacts', [
            'contactable_type' => 'user',
            'contactable_id' => $user->id,
            'value' => 'second-account-contact@example.test',
            'is_primary' => false,
        ]);
        $this->assertSame(1, $user->contacts()->where('is_primary', true)->count());
    }

    public function test_user_can_switch_primary_contact(): void
    {
        $user = User::factory()->create([
            'username' => 'switch_primary_contact_user',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $oldPrimary = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'old-primary@example.test',
            'is_primary' => true,
            'verified_at' => now(),
        ]);
        $newPrimary = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'new-primary@example.test',
            'is_primary' => false,
            'verified_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->patch(route('account.contacts.primary', $newPrimary));

        $response->assertRedirect(route('account.contacts'));
        $response->assertSessionHas('status', 'Основной контакт обновлен.');
        $this->assertFalse($oldPrimary->fresh()->is_primary);
        $this->assertTrue($newPrimary->fresh()->is_primary);
        $this->assertSame(1, $user->contacts()->where('is_primary', true)->count());
    }

    public function test_user_cannot_switch_primary_to_another_users_contact(): void
    {
        $owner = User::factory()->create([
            'username' => 'primary_owner',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $intruder = User::factory()->create([
            'username' => 'primary_intruder',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $contact = $owner->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'primary-owner@example.test',
            'is_primary' => false,
        ]);

        $response = $this->actingAs($intruder)
            ->patch(route('account.contacts.primary', $contact));

        $response->assertNotFound();
        $this->assertFalse($contact->fresh()->is_primary);
    }

    public function test_contacts_page_shows_set_primary_action_for_secondary_contact(): void
    {
        $user = User::factory()->create([
            'username' => 'primary_action_user',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'primary-action@example.test',
            'is_primary' => true,
        ]);
        $secondary = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'secondary-action@example.test',
            'is_primary' => false,
        ]);

        $response = $this->actingAs($user)->get(route('account.contacts'));

        $response->assertOk();
        $response->assertSee(route('account.contacts.primary', $secondary), false);
        $response->assertSee('Сделать основным');
    }

    public function test_user_can_start_email_contact_verification(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'username' => 'verify_contact_user',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'verify-contact@example.test',
        ]);

        $response = $this->actingAs($user)
            ->post(route('account.contacts.verification.store', $contact));

        $response->assertRedirect(route('account.contacts'));
        $this->assertDatabaseHas('contact_verifications', [
            'contact_id' => $contact->id,
            'channel' => ContactVerificationChannelEnum::EMAIL->value,
            'status' => ContactVerificationStatusEnum::PENDING->value,
            'sent_to' => 'verify-contact@example.test',
            'attempts_count' => 0,
            'max_attempts' => 5,
        ]);
        Mail::assertSent(ContactVerificationCodeMail::class, function (ContactVerificationCodeMail $mail) use ($contact): bool {
            return $mail->verification->contact_id === $contact->id
                && $mail->code !== '';
        });
    }

    public function test_user_cannot_request_new_email_code_during_cooldown(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'username' => 'cooldown_contact_user',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'cooldown@example.test',
        ]);

        $this->actingAs($user)->post(route('account.contacts.verification.store', $contact));

        Mail::assertSent(ContactVerificationCodeMail::class, 1);

        $response = $this->actingAs($user)->post(route('account.contacts.verification.store', $contact));

        $response->assertRedirect(route('account.contacts'));
        $response->assertSessionHas('info');
        $response->assertSessionHas('contactVerificationCooldownSeconds');
        $this->assertDatabaseCount('contact_verifications', 1);
        Mail::assertSent(ContactVerificationCodeMail::class, 1);
    }

    public function test_user_can_request_new_email_code_after_cooldown(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'username' => 'resend_contact_user',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'resend@example.test',
        ]);

        $this->actingAs($user)->post(route('account.contacts.verification.store', $contact));

        $firstVerification = $contact->verifications()->first();
        $firstVerification->forceFill([
            'created_at' => now()->subSeconds(61),
            'updated_at' => now()->subSeconds(61),
        ])->save();

        $response = $this->actingAs($user)->post(route('account.contacts.verification.store', $contact));

        $response->assertRedirect(route('account.contacts'));
        $this->assertDatabaseHas('contact_verifications', [
            'id' => $firstVerification->id,
            'status' => ContactVerificationStatusEnum::CANCELLED->value,
        ]);
        $this->assertDatabaseHas('contact_verifications', [
            'contact_id' => $contact->id,
            'status' => ContactVerificationStatusEnum::PENDING->value,
        ]);
        $this->assertSame(2, $contact->verifications()->count());
        Mail::assertSent(ContactVerificationCodeMail::class, 2);
    }

    public function test_user_cannot_start_verification_for_another_users_contact(): void
    {
        Mail::fake();

        $owner = User::factory()->create([
            'username' => 'contact_owner',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $intruder = User::factory()->create([
            'username' => 'contact_intruder',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $contact = $owner->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'owner@example.test',
        ]);

        $response = $this->actingAs($intruder)
            ->post(route('account.contacts.verification.store', $contact));

        $response->assertNotFound();
        $this->assertDatabaseCount('contact_verifications', 0);
        Mail::assertNothingSent();
    }

    public function test_user_cannot_start_verification_for_unsupported_contact_type(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'username' => 'unsupported_contact_user',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::PHONE,
            'value' => '+79990000000',
        ]);

        $response = $this->actingAs($user)
            ->post(route('account.contacts.verification.store', $contact));

        $response->assertRedirect(route('account.contacts'));
        $response->assertSessionHas('error', 'Для этого типа контакта подтверждение пока не реализовано.');
        $this->assertDatabaseCount('contact_verifications', 0);
        Mail::assertNothingSent();
    }

    public function test_user_can_confirm_email_contact_verification_with_code(): void
    {
        $user = User::factory()->create([
            'username' => 'confirm_contact_user',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'confirm@example.test',
        ]);

        $verification = $contact->verifications()->create([
            'channel' => ContactVerificationChannelEnum::EMAIL,
            'status' => ContactVerificationStatusEnum::PENDING,
            'code_hash' => Hash::make('123456'),
            'sent_to' => 'confirm@example.test',
            'attempts_count' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->actingAs($user)
            ->post(route('account.contacts.verification.confirm', $contact), [
                'code' => '123456',
            ]);

        $response->assertRedirect(route('account.contacts'));
        $response->assertSessionHas('status', 'Контакт подтвержден.');
        $this->assertDatabaseHas('contact_verifications', [
            'id' => $verification->id,
            'status' => ContactVerificationStatusEnum::CONFIRMED->value,
            'attempts_count' => 0,
        ]);
        $this->assertNotNull($verification->fresh()->verified_at);
        $this->assertNotNull($contact->fresh()->verified_at);
    }

    public function test_wrong_contact_verification_code_increments_attempts(): void
    {
        $user = User::factory()->create([
            'username' => 'wrong_code_contact_user',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'wrong-code@example.test',
        ]);

        $verification = $contact->verifications()->create([
            'channel' => ContactVerificationChannelEnum::EMAIL,
            'status' => ContactVerificationStatusEnum::PENDING,
            'code_hash' => Hash::make('123456'),
            'sent_to' => 'wrong-code@example.test',
            'attempts_count' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->actingAs($user)
            ->post(route('account.contacts.verification.confirm', $contact), [
                'code' => '654321',
            ]);

        $response->assertRedirect(route('account.contacts'));
        $response->assertSessionHas('error', 'Неверный код. Осталось попыток: 4.');
        $this->assertDatabaseHas('contact_verifications', [
            'id' => $verification->id,
            'status' => ContactVerificationStatusEnum::PENDING->value,
            'attempts_count' => 1,
        ]);
        $this->assertNull($contact->fresh()->verified_at);
    }

    public function test_wrong_contact_verification_code_marks_failed_after_max_attempts(): void
    {
        $user = User::factory()->create([
            'username' => 'failed_code_contact_user',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'failed-code@example.test',
        ]);

        $verification = $contact->verifications()->create([
            'channel' => ContactVerificationChannelEnum::EMAIL,
            'status' => ContactVerificationStatusEnum::PENDING,
            'code_hash' => Hash::make('123456'),
            'sent_to' => 'failed-code@example.test',
            'attempts_count' => 4,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->actingAs($user)
            ->post(route('account.contacts.verification.confirm', $contact), [
                'code' => '654321',
            ]);

        $response->assertRedirect(route('account.contacts'));
        $response->assertSessionHas('error', 'Неверный код. Лимит попыток исчерпан. Запросите новый код.');
        $this->assertDatabaseHas('contact_verifications', [
            'id' => $verification->id,
            'status' => ContactVerificationStatusEnum::FAILED->value,
            'attempts_count' => 5,
        ]);
        $this->assertNotNull($verification->fresh()->failed_at);
        $this->assertNull($contact->fresh()->verified_at);
    }

    public function test_expired_contact_verification_code_marks_expired(): void
    {
        $user = User::factory()->create([
            'username' => 'expired_code_contact_user',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'expired-code@example.test',
        ]);

        $verification = $contact->verifications()->create([
            'channel' => ContactVerificationChannelEnum::EMAIL,
            'status' => ContactVerificationStatusEnum::PENDING,
            'code_hash' => Hash::make('123456'),
            'sent_to' => 'expired-code@example.test',
            'attempts_count' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs($user)
            ->post(route('account.contacts.verification.confirm', $contact), [
                'code' => '123456',
            ]);

        $response->assertRedirect(route('account.contacts'));
        $response->assertSessionHas('error', 'Срок действия кода истек. Запросите новый код.');
        $this->assertDatabaseHas('contact_verifications', [
            'id' => $verification->id,
            'status' => ContactVerificationStatusEnum::EXPIRED->value,
        ]);
        $this->assertNull($contact->fresh()->verified_at);
    }

    public function test_user_cannot_confirm_another_users_contact(): void
    {
        $owner = User::factory()->create([
            'username' => 'confirm_owner',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $intruder = User::factory()->create([
            'username' => 'confirm_intruder',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $contact = $owner->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'confirm-owner@example.test',
        ]);

        $verification = $contact->verifications()->create([
            'channel' => ContactVerificationChannelEnum::EMAIL,
            'status' => ContactVerificationStatusEnum::PENDING,
            'code_hash' => Hash::make('123456'),
            'sent_to' => 'confirm-owner@example.test',
            'attempts_count' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->actingAs($intruder)
            ->post(route('account.contacts.verification.confirm', $contact), [
                'code' => '123456',
            ]);

        $response->assertNotFound();
        $this->assertDatabaseHas('contact_verifications', [
            'id' => $verification->id,
            'status' => ContactVerificationStatusEnum::PENDING->value,
            'attempts_count' => 0,
        ]);
        $this->assertNull($contact->fresh()->verified_at);
    }

    public function test_user_can_delete_non_primary_contact(): void
    {
        $user = User::factory()->create([
            'username' => 'delete_contact_user',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'primary-delete@example.test',
            'is_primary' => true,
        ]);

        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'secondary-delete@example.test',
            'is_primary' => false,
            'is_public' => true,
            'verified_at' => now(),
        ]);

        $pendingVerification = $contact->verifications()->create([
            'channel' => ContactVerificationChannelEnum::EMAIL,
            'status' => ContactVerificationStatusEnum::PENDING,
            'code_hash' => Hash::make('123456'),
            'sent_to' => 'secondary-delete@example.test',
            'expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->actingAs($user)
            ->delete(route('account.contacts.destroy', $contact));

        $response->assertRedirect(route('account.contacts'));
        $response->assertSessionHas('status', 'Контакт удален.');
        $this->assertSoftDeleted('contacts', [
            'id' => $contact->id,
        ]);
        $deletedContact = $user->contacts()->withTrashed()->find($contact->id);
        $this->assertTrue($deletedContact->trashed());
        $this->assertNull($deletedContact->verified_at);
        $this->assertFalse($deletedContact->is_public);
        $this->assertDatabaseHas('contact_verifications', [
            'id' => $pendingVerification->id,
            'status' => ContactVerificationStatusEnum::CANCELLED->value,
        ]);
        $this->assertSame(1, $user->contacts()->count());
    }

    public function test_user_cannot_delete_primary_contact(): void
    {
        $user = User::factory()->create([
            'username' => 'delete_primary_contact_user',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'primary-delete-denied@example.test',
            'is_primary' => true,
        ]);

        $response = $this->actingAs($user)
            ->delete(route('account.contacts.destroy', $contact));

        $response->assertRedirect(route('account.contacts'));
        $response->assertSessionHas('error', 'Нельзя удалить основной контакт.');
        $this->assertNotSoftDeleted('contacts', [
            'id' => $contact->id,
        ]);
    }

    public function test_user_cannot_delete_another_users_contact(): void
    {
        $owner = User::factory()->create([
            'username' => 'delete_owner',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $intruder = User::factory()->create([
            'username' => 'delete_intruder',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $contact = $owner->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'delete-owner@example.test',
            'is_primary' => false,
        ]);

        $response = $this->actingAs($intruder)
            ->delete(route('account.contacts.destroy', $contact));

        $response->assertNotFound();
        $this->assertNotSoftDeleted('contacts', [
            'id' => $contact->id,
        ]);
    }

    public function test_user_restores_same_contact_value_after_soft_delete(): void
    {
        $user = User::factory()->create([
            'username' => 'readd_deleted_contact_user',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'readd-primary@example.test',
            'is_primary' => true,
        ]);

        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'readd-deleted@example.test',
            'is_primary' => false,
            'verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->delete(route('account.contacts.destroy', $contact));

        $response = $this->actingAs($user)->post(route('account.contacts.store'), [
            'type' => ContactTypeEnum::EMAIL->value,
            'value' => 'readd-deleted@example.test',
        ]);

        $response->assertRedirect(route('account.contacts'));
        $response->assertSessionHas('status', 'Контакт добавлен.');
        $this->assertDatabaseHas('contacts', [
            'id' => $contact->id,
            'value' => 'readd-deleted@example.test',
            'verified_at' => null,
            'deleted_at' => null,
        ]);
        $this->assertSame(2, $user->contacts()->count());
        $this->assertSame(0, $user->contacts()->onlyTrashed()->count());
    }
}
