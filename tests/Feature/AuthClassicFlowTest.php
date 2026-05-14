<?php

namespace Tests\Feature;

use App\Modules\Contact\Domain\Enums\ContactStatusEnum;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\ContactVerification\Domain\Enums\ContactVerificationPurposeEnum;
use App\Modules\ContactVerification\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\ContactVerification\Domain\Models\ContactVerification;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthClassicFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_first_registration_creates_user_contact_and_verification(): void
    {
        Mail::fake();

        $response = $this->postJson(route('auth.register'), [
            'email' => 'NewUser@example.com',
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'status' => 'temp_password_sent',
                'login' => 'newuser@example.com',
                'next' => 'login',
            ]);

        $user = User::query()->firstOrFail();
        $this->assertTrue($user->is_temp_password);
        $this->assertSame(UserStatusEnum::UNCONFIRMED, $user->status);
        $this->assertSame(UserRegistrationChannelEnum::SITE_CONTACT_FIRST, $user->registration_channel);

        $contact = Contact::query()->firstOrFail();
        $this->assertSame('user', $contact->entity_type);
        $this->assertSame($user->id, $contact->entity_id);
        $this->assertSame(ContactTypeEnum::EMAIL, $contact->contact_type);
        $this->assertSame('newuser@example.com', $contact->value);
        $this->assertSame(ContactStatusEnum::UNVERIFIED, $contact->status);

        $verification = ContactVerification::query()->firstOrFail();
        $this->assertSame($contact->id, $verification->contact_id);
        $this->assertSame(ContactVerificationPurposeEnum::SITE_CONTACT_FIRST, $verification->purpose);
        $this->assertSame(ContactVerificationStatusEnum::PENDING, $verification->status);
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{6}$/', (string) $verification->value);
    }

    public function test_first_login_with_temporary_password_verifies_contact(): void
    {
        $user = User::query()->create([
            'password' => 'Abc123',
            'is_temp_password' => true,
            'registration_channel' => UserRegistrationChannelEnum::SITE_CONTACT_FIRST,
            'status' => UserStatusEnum::UNCONFIRMED,
        ]);

        $contact = Contact::query()->create([
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'contact_type' => ContactTypeEnum::EMAIL,
            'value' => 'first-login@example.com',
            'status' => ContactStatusEnum::UNVERIFIED,
        ]);

        ContactVerification::query()->create([
            'contact_id' => $contact->id,
            'purpose' => ContactVerificationPurposeEnum::SITE_CONTACT_FIRST,
            'status' => ContactVerificationStatusEnum::PENDING,
        ]);

        $response = $this->postJson(route('auth.login'), [
            'login' => 'first-login@example.com',
            'password' => 'Abc123',
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'status' => 'authenticated',
            ]);

        $this->assertAuthenticatedAs($user);
        $this->assertSame(ContactStatusEnum::VERIFIED, $contact->fresh()->status);

        $verification = ContactVerification::query()->firstOrFail();
        $this->assertSame(ContactVerificationStatusEnum::VERIFIED, $verification->status);
        $this->assertNotNull($verification->verified_at);
        $this->assertSame(UserStatusEnum::UNCONFIRMED, $user->fresh()->status);
    }

    public function test_restore_returns_temporary_not_implemented_message(): void
    {
        $response = $this->post(route('auth.restore'), [
            'contact' => 'restore@example.com',
        ]);

        $response
            ->assertStatus(501)
            ->assertJson([
                'status' => 'not_implemented',
                'message' => 'Восстановление пароля пока не реализовано. Сорян, но мы работаем над этим! Прямо сейчас!!!',
            ]);
    }
}
