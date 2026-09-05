<?php

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class YandexMetrikaTest extends TestCase
{
    use RefreshDatabase;

    public function test_counter_is_not_rendered_without_configured_id(): void
    {
        config(['services.yandex_metrika.id' => null]);

        $this
            ->get(route('welcome'))
            ->assertOk()
            ->assertDontSee('mc.yandex.ru/metrika/tag.js', false)
            ->assertDontSee('mc.yandex.ru/watch/', false);
    }

    public function test_counter_is_rendered_on_regular_and_telegram_pages(): void
    {
        config(['services.yandex_metrika.id' => '12345678']);

        foreach ([route('welcome'), route('integrations.telegram.main')] as $url) {
            $this
                ->get($url)
                ->assertOk()
                ->assertSee('https://mc.yandex.ru/metrika/tag.js', false)
                ->assertSee('ym(12345678, "init"', false)
                ->assertSee('referrer: document.referrer', false)
                ->assertSee('url: location.href', false)
                ->assertSee('https://mc.yandex.ru/watch/12345678', false);
        }
    }

    public function test_invalid_counter_id_is_not_rendered(): void
    {
        config(['services.yandex_metrika.id' => '123<script>']);

        $this
            ->get(route('welcome'))
            ->assertOk()
            ->assertDontSee('mc.yandex.ru/metrika/tag.js', false)
            ->assertDontSee('123<script>', false);
    }
}
