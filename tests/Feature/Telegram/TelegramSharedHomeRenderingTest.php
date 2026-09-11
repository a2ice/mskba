<?php

namespace Tests\Feature\Telegram;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramSharedHomeRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_entry_exposes_shared_home_selector_sources(): void
    {
        $this
            ->get(route('integrations.telegram.main'))
            ->assertOk()
            ->assertSee('data-home-event-venue-selector-source', false)
            ->assertSee('data-home-venue-selector-source', false)
            ->assertSee('data-home-venue-section-selector-source', false);
    }
}
