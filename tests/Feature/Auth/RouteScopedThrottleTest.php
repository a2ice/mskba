<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RouteScopedThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_attempts_on_one_auth_route_do_not_block_other_auth_methods(): void
    {
        foreach (range(1, 5) as $_) {
            $this->post(route('auth.login'), [
                'login' => 'missing-user',
                'password' => 'wrong-password',
            ])->assertStatus(302);
        }

        $this->post(route('auth.login'), [
            'login' => 'missing-user',
            'password' => 'wrong-password',
        ])->assertStatus(429);

        $this->postJson(route('auth.telegram'), [
            'telegram_user' => [],
        ])->assertStatus(422);

        $this->get(route('auth.vk.start'))->assertRedirect();
    }

    public function test_browser_error_responses_use_the_mskba_error_page(): void
    {
        config(['app.debug' => false]);

        $this->get('/definitely-missing-page')
            ->assertNotFound()
            ->assertSee('Страница не найдена')
            ->assertSee('Московская Баскетбольная Ассоциация');
    }
}
