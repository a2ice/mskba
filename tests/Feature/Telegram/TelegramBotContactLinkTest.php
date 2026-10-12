<?php

namespace Tests\Feature\Telegram;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Application\UseCases\HandleTelegramBotLinkCallback;
use App\Modules\Telegram\Application\UseCases\HandleTelegramBotLoginStartMessage;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TelegramBotContactLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'telegram.bot_token' => '123456:test-token',
            'telegram.bot_username' => 'MSKBABot',
            'telegram.bot_login_ttl' => 300,
        ]);
        Http::fake([
            'https://api.telegram.org/bot123456:test-token/*' => Http::response([
                'ok' => true, 'result' => true,
            ]),
        ]);
    }

    private function user(): User
    {
        $user = User::factory()->create();
        $user->createProfile([]);
        return $user;
    }

    private function startLink(User $user): string
    {
        $result = $this->actingAs($user)
            ->postJson(route('account.telegram.bot.link.start'))
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('bot_url', fn (string $url): bool => str_starts_with(
                $url,
                'https://t.me/MSKBABot?start=link_',
            ));

        return (string) $result->json('token');
    }

    /** @return array<string, mixed> */
    private function linkCallback(string $token, int $telegramUserId = 777): array
    {
        return [
            'id' => 'contact-link-callback-1',
            'from' => [
                'id' => $telegramUserId, 'username' => 'signed_bot_user',
                'first_name' => 'Bot', 'last_name' => 'Player',
            ],
            'message' => [
                'message_id' => 120,
                'chat' => ['id' => $telegramUserId, 'type' => 'private'],
            ],
            'data' => "auth:link:{$token}",
        ];
    }

    public function test_authenticated_user_links_telegram_through_bot_without_logging_out_or_creating_new_user(): void
    {
        $user = $this->user();
        $token = $this->startLink($user);

        $this->postJson(route('account.telegram.bot.link.status'), ['token' => $token])
            ->assertOk()->assertJsonPath('status', 'pending');

        app(HandleTelegramBotLoginStartMessage::class)->handle([
            'from' => ['id' => 777],
            'chat' => ['id' => 777, 'type' => 'private'],
            'text' => "/start link_{$token}",
        ]);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data')
                === "auth:link:{$token}");
        app(HandleTelegramBotLinkCallback::class)->handle($this->linkCallback($token));

        // Bot approval cannot create a contact before the originating browser consumes it.
        $this->assertDatabaseMissing('telegram_accounts', ['telegram_user_id' => 777]);

        $this->postJson(route('account.telegram.bot.link.status'), ['token' => $token])
            ->assertOk()->assertJsonPath('status', 'success');
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::query()->count());
        $this->assertDatabaseHas('telegram_accounts', [
            'telegram_user_id' => 777, 'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('contacts', [
            'contactable_type' => 'user', 'contactable_id' => $user->id,
            'type' => 'telegram', 'value' => '777', 'is_primary' => true,
        ]);
        $this->assertTrue($user->hasVerifiedPrimaryContact());

        $this->postJson(route('account.telegram.bot.link.status'), ['token' => $token])
            ->assertStatus(410)->assertJsonPath('status', 'expired');
    }

    public function test_another_account_cannot_consume_approved_link_token(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $token = $this->startLink($owner);
        app(HandleTelegramBotLinkCallback::class)->handle($this->linkCallback($token));

        // Even when sharing the browser session, another logged-in user cannot claim this link.
        $this->actingAs($other)->postJson(route('account.telegram.bot.link.status'), ['token' => $token])
            ->assertUnprocessable();
        $this->assertDatabaseMissing('telegram_accounts', ['telegram_user_id' => 777]);
        $this->actingAs($owner)->postJson(route('account.telegram.bot.link.status'), ['token' => $token])
            ->assertOk()->assertJsonPath('status', 'success');
        $this->assertDatabaseHas('telegram_accounts', ['telegram_user_id' => 777, 'user_id' => $owner->id]);
    }

    public function test_existing_telegram_owned_by_different_account_is_not_reassigned(): void
    {
        $existingOwner = $this->user();
        $requester = $this->user();
        TelegramAccount::query()->create([
            'user_id' => $existingOwner->id, 'telegram_user_id' => 888,
            'username' => 'another_user',
        ]);
        $token = $this->startLink($requester);
        app(HandleTelegramBotLinkCallback::class)->handle($this->linkCallback($token, 888));

        $this->postJson(route('account.telegram.bot.link.status'), ['token' => $token])
            ->assertStatus(409)
            ->assertJsonPath('status', 'duplicate');
        $this->assertDatabaseHas('telegram_accounts', [
            'telegram_user_id' => 888,
            'user_id' => $existingOwner->id,
        ]);
        $this->assertDatabaseMissing('contacts', [
            'contactable_id' => $requester->id, 'type' => 'telegram', 'value' => '888',
        ]);
        $this->assertAuthenticatedAs($requester);
    }

    public function test_guest_cannot_start_contact_link(): void
    {
        $this->postJson(route('account.telegram.bot.link.start'))->assertUnauthorized();
    }
}
