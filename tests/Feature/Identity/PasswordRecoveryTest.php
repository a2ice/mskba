<?php

namespace Tests\Feature\Identity;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Presentation\Mail\TemporaryPasswordMail;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $password = 'OldStrong1!'): User
    {
        return User::factory()->create([
            'password' => $password,
            'status' => UserStatusEnum::CONFIRMED,
            'is_temporary_password' => false,
        ]);
    }

    private function contact(User $user, string $value, bool $verified = true): Contact
    {
        return Contact::query()->create([
            'contactable_type' => 'user',
            'contactable_id' => $user->id,
            'type' => ContactTypeEnum::EMAIL,
            'value' => $value,
            'verified_at' => $verified ? now() : null,
        ]);
    }

    public function test_verified_email_receives_new_temporary_password(): void
    {
        config()->set('mail.default', 'smtp');
        Mail::fake();
        $user = $this->person();
        $this->contact($user, 'recover@example.test');

        $this->postJson(route('auth.restore'), ['contact' => 'recover@example.test'])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $user->refresh();
        $this->assertFalse(Hash::check('OldStrong1!', $user->password));
        $this->assertTrue($user->is_temporary_password);
        Mail::assertSent(TemporaryPasswordMail::class, fn (TemporaryPasswordMail $mail): bool =>
            Hash::check($mail->password, $user->password) && str_contains($mail->password, '!')
        );
    }

    public function test_unknown_and_unverified_email_return_identical_response_without_changing_password(): void
    {
        config()->set('mail.default', 'smtp');
        Mail::fake();
        $user = $this->person();
        $this->contact($user, 'unverified@example.test', false);

        $unknown = $this->postJson(route('auth.restore'), ['contact' => 'unknown@example.test']);
        $unverified = $this->postJson(route('auth.restore'), ['contact' => 'unverified@example.test']);

        $unknown->assertOk();
        $unverified->assertOk();
        $this->assertSame($unknown->json('message'), $unverified->json('message'));
        $this->assertTrue(Hash::check('OldStrong1!', $user->fresh()->password));
        Mail::assertNothingSent();
    }

    public function test_ambiguous_verified_email_is_never_used_to_reset_multiple_accounts(): void
    {
        config()->set('mail.default', 'smtp');
        Mail::fake();
        $first = $this->person();
        $second = $this->person();
        $this->contact($first, 'ambiguous@example.test');
        $this->contact($second, 'ambiguous@example.test');

        $this->postJson(route('auth.restore'), ['contact' => 'ambiguous@example.test'])->assertOk();
        $this->assertTrue(Hash::check('OldStrong1!', $first->fresh()->password));
        $this->assertTrue(Hash::check('OldStrong1!', $second->fresh()->password));
        Mail::assertNothingSent();
    }

    public function test_unavailable_mail_transport_does_not_rotate_credentials(): void
    {
        config()->set('mail.default', 'log');
        Mail::fake();
        $user = $this->person();
        $this->contact($user, 'not-deliverable@example.test');

        $this->postJson(route('auth.restore'), ['contact' => 'not-deliverable@example.test'])->assertOk();

        $this->assertTrue(Hash::check('OldStrong1!', $user->fresh()->password));
        Mail::assertNothingSent();
    }

    public function test_verified_telegram_contact_with_accessible_private_chat_receives_temporary_password(): void
    {
        config()->set('telegram.bot_token', 'test-token');
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $user = $this->person();

        Contact::query()->create([
            'contactable_type' => 'user',
            'contactable_id' => $user->id,
            'type' => ContactTypeEnum::TELEGRAM,
            'value' => '123456789',
            'verified_at' => now(),
            'meta' => ['username' => 'verifiedplayer'],
        ]);
        TelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => 123456789,
            'private_chat_id' => 123456789,
            'private_chat_available_at' => now(),
        ]);

        $this->postJson(route('auth.restore'), ['contact' => '@verifiedplayer'])->assertOk();

        $this->assertTrue($user->fresh()->is_temporary_password);
        Http::assertSent(fn ($request): bool =>
            str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'MSKBA:')
            && (string) $request['chat_id'] === '123456789'
        );
    }
}
