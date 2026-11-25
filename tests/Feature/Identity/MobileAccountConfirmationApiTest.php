<?php

namespace Tests\Feature\Identity;

use App\Modules\Contact\Presentation\Mail\ContactVerificationCodeMail;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class MobileAccountConfirmationApiTest extends TestCase
{
    use RefreshDatabase;

    private function account(array $abilities = ['profile:read', 'profile:write', 'contacts:read', 'contacts:write', 'account:confirm']): User
    {
        $user = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);
        $this->withToken($user->createToken('mobile-test', $abilities)->plainTextToken);

        return $user;
    }

    private function profile(): array
    {
        return ['first_name' => 'Иван', 'last_name' => 'Иванов', 'birth_date' => '1999-01-01', 'gender' => 'male'];
    }

    private function freshGuard(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_full_confirmation_flow_and_contact_code_guards(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();
        $user = $this->account();
        $this->getJson('/api/v1/account/confirmation')->assertOk()->assertJsonPath('data.can_confirm', false)->assertJsonCount(5, 'data.requirements');
        $this->freshGuard();
        $this->patchJson('/api/v1/me/profile', $this->profile())->assertOk();
        $this->freshGuard();
        $this->postJson('/api/v1/account/confirmation', $this->profile())->assertUnprocessable();
        $this->freshGuard();
        $id = $this->postJson('/api/v1/me/contacts', ['type' => 'email', 'value' => 'mobile-confirm@example.org'])
            ->assertCreated()->assertJsonPath('data.is_verified', false)->json('data.id');
        $this->freshGuard();
        $this->patchJson("/api/v1/me/contacts/{$id}/primary")->assertUnprocessable();
        $this->freshGuard();
        $this->postJson("/api/v1/me/contacts/{$id}/verification")->assertOk();
        $code = null;
        Mail::assertSent(ContactVerificationCodeMail::class, function ($mail) use (&$code): bool {
            $code = $mail->code;
            return true;
        });
        $this->freshGuard();
        $this->postJson("/api/v1/me/contacts/{$id}/verification")->assertStatus(429);
        $this->freshGuard();
        $this->postJson("/api/v1/me/contacts/{$id}/verification/confirm", ['code' => $code === '111111' ? '222222' : '111111'])->assertUnprocessable();
        $this->freshGuard();
        $this->postJson("/api/v1/me/contacts/{$id}/verification/confirm", ['code' => $code])->assertOk()->assertJsonPath('data.is_verified', true)->assertJsonPath('data.is_primary', true);
        $this->freshGuard();
        $this->getJson('/api/v1/account/confirmation')->assertOk()->assertJsonPath('data.can_confirm', true);
        $this->freshGuard();
        $this->postJson('/api/v1/account/confirmation', $this->profile())->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertTrue($user->refresh()->isConfirmed());
        $this->freshGuard();
        $this->postJson('/api/v1/account/confirmation', $this->profile())->assertUnprocessable();
        $this->freshGuard();
        $this->getJson('/api/v1/me/contacts')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonMissingPath('data.0.code_hash');
    }

    public function test_read_only_token_cannot_modify_or_list_private_contacts(): void
    {
        $this->account(['profile:read']);
        $this->getJson('/api/v1/account/confirmation')->assertOk();
        $this->freshGuard();
        $this->patchJson('/api/v1/me/profile', $this->profile())->assertForbidden();
        $this->freshGuard();
        $this->getJson('/api/v1/me/contacts')->assertForbidden();
        $this->freshGuard();
        $this->postJson('/api/v1/account/confirmation', $this->profile())->assertForbidden();
    }

    public function test_foreign_contact_and_untrusted_social_values_are_rejected(): void
    {
        $this->account();
        $id = $this->postJson('/api/v1/me/contacts', ['type' => 'email', 'value' => 'foreign-mobile@example.org'])->assertCreated()->json('data.id');
        $this->freshGuard();
        $this->account();
        $this->postJson("/api/v1/me/contacts/{$id}/verification")->assertNotFound();
        $this->freshGuard();
        $this->postJson("/api/v1/me/contacts/{$id}/verification/confirm", ['code' => '123456'])->assertNotFound();
        $this->freshGuard();
        $this->patchJson("/api/v1/me/contacts/{$id}/primary")->assertNotFound();
        $this->freshGuard();
        $this->postJson('/api/v1/me/contacts', ['type' => 'telegram', 'value' => '123456789'])->assertUnprocessable();
    }
}
