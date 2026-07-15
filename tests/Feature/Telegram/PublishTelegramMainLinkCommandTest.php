<?php

namespace Tests\Feature\Telegram;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublishTelegramMainLinkCommandTest extends TestCase
{
    public function test_command_sends_and_pins_main_link_message(): void
    {
        config([
            'telegram.bot_token' => '123456:test-token',
            'telegram.bot_username' => 'MSKBABot',
            'telegram.main_chat_id' => '-1002136558099',
        ]);

        Http::fake([
            'https://api.telegram.org/bot123456:test-token/sendMessage' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 165,
                ],
            ]),
            'https://api.telegram.org/bot123456:test-token/pinChatMessage' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $this
            ->artisan('telegram:publish-main-link')
            ->expectsOutput('Telegram Mini App link message sent. message_id=165')
            ->expectsOutput('Message pinned.')
            ->assertSuccessful();

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.telegram.org/bot123456:test-token/sendMessage'
                && $request['chat_id'] === '-1002136558099'
                && $request['reply_markup']['inline_keyboard'][0][0]['url'] === 'https://t.me/MSKBABot?startapp=mskba_chat';
        });

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.telegram.org/bot123456:test-token/pinChatMessage'
                && $request['chat_id'] === '-1002136558099'
                && $request['message_id'] === 165
                && $request['disable_notification'] === true;
        });
    }

    public function test_command_can_skip_pin(): void
    {
        config([
            'telegram.bot_token' => '123456:test-token',
            'telegram.bot_username' => 'MSKBABot',
            'telegram.main_chat_id' => '-1002136558099',
        ]);

        Http::fake([
            'https://api.telegram.org/bot123456:test-token/sendMessage' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 166,
                ],
            ]),
        ]);

        $this
            ->artisan('telegram:publish-main-link --no-pin')
            ->expectsOutput('Telegram Mini App link message sent. message_id=166')
            ->assertSuccessful();

        Http::assertSentCount(1);
    }
}
