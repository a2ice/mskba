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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactRelationsTest extends TestCase
{
    use RefreshDatabase;

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
}
