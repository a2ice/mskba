<?php

namespace Tests\Feature\Location;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AddressSuggestControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_query_is_required(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->getJson(route('integrations.address-suggest'))
            ->assertUnprocessable();

        $this->assertStringContainsString('"query"', $response->getContent());
    }

    public function test_query_must_have_minimum_length(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->getJson(route('integrations.address-suggest', ['query' => 'мо']))
            ->assertUnprocessable();

        $this->assertStringContainsString('"query"', $response->getContent());
    }

    public function test_valid_query_returns_suggestions_json(): void
    {
        config(['integrations.yandex.api_key' => null]);
        Http::fake();

        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->getJson(route('integrations.address-suggest', ['query' => 'Москва Летниковская 12']))
            ->assertOk()
            ->assertExactJson([
                'suggestions' => [],
            ]);

        Http::assertNothingSent();
    }
}
