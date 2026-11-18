<?php

namespace Tests\Feature\Identity;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\ContactVerification;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Presentation\Mail\AccountRestoreCodeMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class RestoreOwnAccountApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('mail.default', 'smtp');
        Mail::fake();
    }

    private function account(string $email = 'restore-mskba@example.org'): User
    {
        $user = User::factory()->create([
            'username' => 'restore_self',
            'password' => 'Secret#pass123',
            'status' => UserStatusEnum::UNCONFIRMED->value,
            'is_temporary_password' => false,
        ]);
        $user->createProfile([]);
        $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => $email,
            'verified_at' => now(),
            'is_primary' => true,
        ]);

        return $user;
    }

    private function requestCode(string $email): string
    {
        $this->postJson(route('api.v1.account.restore.request'), [
            'contact' => $email,
        ])->assertOk()->assertJsonStructure(['message']);

        $code = null;
        Mail::assertSent(AccountRestoreCodeMail::class, function (AccountRestoreCodeMail $mail) use (&$code): bool {
            $code = $mail->code;
            return true;
        });
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/D', $code);

        return $code;
    }

    public function test_self_deleted_account_restores_after_verified_email_code_without_bearer(): void
    {
        $user = $this->account();
        $token = $user->createToken('ios', ['profile:read'])->plainTextToken;

        $this->actingAs($user)->delete(route('account.destroy'))
            ->assertRedirect(route('welcome'));
        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertNotNull(User::withTrashed()->findOrFail($user->id)->self_deleted_at);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $code = $this->requestCode('restore-mskba@example.org');

        $this->postJson(route('api.v1.account.restore.confirm'), [
            'contact' => 'restore-mskba@example.org',
            'code' => $code,
        ])->assertOk()
            ->assertJsonPath('restored', true);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'deleted_at' => null, 'self_deleted_at' => null]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson(route('api.v1.me'))->assertUnauthorized();

        $this->postJson(route('api.v1.auth.login'), [
            'login' => 'restore_self',
            'password' => 'Secret#pass123',
        ])->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_restores_canonical_identity_with_aliases_without_creating_a_new_user(): void
    {
        $user = $this->account();
        $alias = User::factory()->create(['canonical_user_id' => $user->id]);
        $count = User::withTrashed()->count();

        $this->actingAs($user)->delete(route('account.destroy'));
        $this->assertSoftDeleted('users', ['id' => $alias->id]);
        $code = $this->requestCode('restore-mskba@example.org');

        $this->postJson(route('api.v1.account.restore.confirm'), [
            'contact' => 'restore-mskba@example.org',
            'code' => $code,
        ])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $alias->id, 'deleted_at' => null]);
        $this->assertSame($count, User::withTrashed()->count());
    }

    public function test_administratively_deleted_account_cannot_restore_via_public_api(): void
    {
        $user = $this->account();
        $user->delete();

        $this->postJson(route('api.v1.account.restore.request'), [
            'contact' => 'restore-mskba@example.org',
        ])->assertOk();

        Mail::assertNothingSent();
        $this->postJson(route('api.v1.account.restore.confirm'), [
            'contact' => 'restore-mskba@example.org', 'code' => '123456',
        ])->assertUnprocessable();
        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_unknown_contact_returns_same_generic_request_response(): void
    {
        $this->postJson(route('api.v1.account.restore.request'), [
            'contact' => 'unknown-mskba@example.org',
        ])->assertOk()
            ->assertJsonPath('message', 'Если аккаунт доступен для восстановления, код отправлен на подтверждённый контакт.');

        Mail::assertNothingSent();
        $this->postJson(route('api.v1.account.restore.request'), [
            'contact' => 'not-an-email',
        ])->assertUnprocessable()->assertJsonValidationErrors('contact');
    }

    public function test_invalid_code_has_five_attempt_limit_and_is_one_time_only(): void
    {
        $user = $this->account();
        $this->actingAs($user)->delete(route('account.destroy'));
        $code = $this->requestCode('restore-mskba@example.org');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('api.v1.account.restore.confirm'), [
                'contact' => 'restore-mskba@example.org',
                'code' => $code === '000000' ? '000001' : '000000',
            ])->assertUnprocessable();
        }

        $this->postJson(route('api.v1.account.restore.confirm'), [
            'contact' => 'restore-mskba@example.org', 'code' => $code,
        ])->assertUnprocessable();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_expired_restore_code_does_not_restore_account(): void
    {
        $user = $this->account();
        $this->actingAs($user)->delete(route('account.destroy'));
        $code = $this->requestCode('restore-mskba@example.org');

        ContactVerification::query()
            ->where('meta->purpose', 'account_restore')
            ->update(['expires_at' => now()->subMinute()]);

        $this->postJson(route('api.v1.account.restore.confirm'), [
            'contact' => 'restore-mskba@example.org', 'code' => $code,
        ])->assertUnprocessable();
        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }
}
