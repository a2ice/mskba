<?php

namespace Tests\Feature\Telegram;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TelegramLoginWidgetFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_login_page_exposes_official_telegram_login_widget_and_bot_fallback(): void
    {
        config(['telegram.bot_username' => 'MSKBABot']);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('https://telegram.org/js/telegram-widget.js?22', false)
            ->assertSee('data-telegram-login="MSKBABot"', false)
            ->assertSee('data-onauth="mskbaTelegramLogin(user)"', false)
            ->assertSee('Войти через Telegram-бота');
    }
}
