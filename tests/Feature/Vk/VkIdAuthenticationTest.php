<?php

namespace Tests\Feature\Vk;

use App\Modules\Identity\Domain\Enums\UserDuplicateEvidenceTypeEnum;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Vk\Domain\Models\VkAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class VkIdAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'vk.app_id' => '12345',
            'vk.redirect_uri' => 'https://mskba.test/auth/vk/callback',
            'vk.authorize_url' => 'https://id.vk.test/authorize',
            'vk.token_url' => 'https://id.vk.test/oauth2/auth',
            'vk.user_info_url' => 'https://id.vk.test/oauth2/user_info',
        ]);
    }

    public function test_start_creates_pkce_flow_and_safe_redirect(): void
    {
        $response = $this->get(route('auth.vk.start', ['redirect_to' => '/account']));

        $response->assertRedirectContains('https://id.vk.test/authorize?');
        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('12345', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('s256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['code_challenge']);
        $this->assertArrayHasKey($query['state'], session('vk.oauth_flows'));
        $this->assertSame(url('/account'), session('vk.oauth_flows')[$query['state']]['redirect_url']);
    }

    public function test_callback_creates_user_and_logs_in(): void
    {
        $state = $this->startFlow();
        $this->fakeVk($state, '777');

        $this->get(route('auth.vk.callback', [
            'state' => $state,
            'code' => 'authorization-code',
            'device_id' => 'device-1',
        ]))->assertRedirect(url('/account'));

        $user = User::query()->where('username', 'vk_777')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(UserRegistrationChannelEnum::VK_ID, $user->registration_channel);
        $this->assertDatabaseHas('vk_accounts', ['user_id' => $user->id, 'vk_user_id' => '777']);
        $this->assertSame('Иван', $user->profile?->first_name);
    }

    public function test_callback_reuses_existing_vk_identity_and_rejects_replayed_state(): void
    {
        $user = User::factory()->create(['username' => 'existing']);
        VkAccount::query()->create(['user_id' => $user->id, 'vk_user_id' => '777']);
        $state = $this->startFlow();
        $this->fakeVk($state, '777');
        $parameters = ['state' => $state, 'code' => 'code', 'device_id' => 'device'];

        $this->get(route('auth.vk.callback', $parameters))->assertRedirect(url('/account'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::query()->count());

        $this->post(route('auth.logout'));
        $this->get(route('auth.vk.callback', $parameters))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Сессия входа через VK ID истекла. Начните вход заново.');
    }

    public function test_blocked_vk_user_is_not_authenticated(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::BLOCKED]);
        VkAccount::query()->create(['user_id' => $user->id, 'vk_user_id' => '777']);
        $state = $this->startFlow();
        $this->fakeVk($state, '777');

        $this->get(route('auth.vk.callback', ['state' => $state, 'code' => 'code', 'device_id' => 'device']))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Аккаунт заблокирован. Обратитесь в поддержку.');
        $this->assertGuest();
    }

    public function test_callback_rejects_unknown_state_without_calling_vk(): void
    {
        Http::fake();

        $this->get(route('auth.vk.callback', [
            'state' => 'unknown-state',
            'code' => 'code',
            'device_id' => 'device',
        ]))->assertRedirect(route('login'))->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_vk_identity_attached_to_alias_logs_in_canonical_user(): void
    {
        $canonical = User::factory()->create();
        $alias = User::factory()->create();
        $alias->forceFill(['canonical_user_id' => $canonical->id])->save();
        VkAccount::query()->create(['user_id' => $alias->id, 'vk_user_id' => '777']);
        $state = $this->startFlow();
        $this->fakeVk($state, '777');

        $this->get(route('auth.vk.callback', ['state' => $state, 'code' => 'code', 'device_id' => 'device']))
            ->assertRedirect(url('/account'));

        $this->assertAuthenticatedAs($canonical);
    }

    public function test_disabled_vk_configuration_hides_button_and_returns_controlled_error(): void
    {
        config(['vk.app_id' => null]);

        $this->get(route('login'))->assertOk()->assertDontSee('Войти через VK ID');
        $this->get(route('auth.vk.start'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Вход через VK ID сейчас недоступен.');
    }

    public function test_linking_vk_owned_by_another_user_creates_duplicate_without_reassigning_it(): void
    {
        $current = User::factory()->create();
        $owner = User::factory()->create();
        VkAccount::query()->create(['user_id' => $owner->id, 'vk_user_id' => '777']);
        $this->actingAs($current);
        $state = $this->startFlow(route('account.vk.link'));
        $this->fakeVk($state, '777');

        $this->get(route('auth.vk.callback', ['state' => $state, 'code' => 'code', 'device_id' => 'device']))
            ->assertRedirect(route('account.vk'))
            ->assertSessionHas('warning');

        $this->assertSame($owner->id, VkAccount::query()->where('vk_user_id', '777')->sole()->user_id);
        $this->assertDatabaseHas('user_duplicate_evidence', [
            'type' => UserDuplicateEvidenceTypeEnum::VK_IDENTITY->value,
            'is_active' => true,
        ]);
    }

    private function startFlow(?string $url = null): string
    {
        $this->get($url ?? route('auth.vk.start', ['redirect_to' => '/account']))->assertRedirect();
        $flows = session('vk.oauth_flows');

        return (string) array_key_first($flows);
    }

    private function fakeVk(string $state, string $userId): void
    {
        Http::fake([
            'id.vk.test/oauth2/auth*' => Http::response([
                'access_token' => 'access-token',
                'user_id' => $userId,
                'state' => $state,
            ]),
            'id.vk.test/oauth2/user_info*' => Http::response([
                'user' => [
                    'user_id' => $userId,
                    'first_name' => 'Иван',
                    'last_name' => 'Петров',
                    'avatar' => 'https://example.test/avatar.jpg',
                ],
            ]),
        ]);
    }
}
