<?php

namespace Tests\Feature\Backoffice;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Portal\Application\Services\SocialIntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SocialIntegrationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
            'password' => Hash::make('test-password'),
        ]);
    }

    public function test_environment_fallback_and_encrypted_storage_preserve_blank_secrets(): void
    {
        config(['telegram.bot_token' => '123456:old-secret', 'telegram.webhook_secret' => 'old-webhook']);
        $settings = app(SocialIntegrationSettings::class);
        $settings->apply();
        $this->assertSame('123456:old-secret', config('telegram.bot_token'));
        $settings->save('telegram', ['bot_username' => 'MSKBABot', 'bot_token' => null], $this->admin()->id);
        $row = DB::table('social_integration_settings')->first();
        $this->assertStringNotContainsString('old-secret', $row->encrypted_settings);
        $this->assertStringNotContainsString('old-webhook', $row->encrypted_settings);
        config(['telegram.bot_token' => null, 'telegram.webhook_secret' => null]);
        $settings->apply();
        $this->assertSame('123456:old-secret', config('telegram.bot_token'));
        $this->assertSame('old-webhook', config('telegram.webhook_secret'));
        $this->assertTrue($settings->display('telegram')['bot_token']);
        $this->assertTrue($settings->display('telegram')['webhook_secret']);
    }

    public function test_vk_oauth_state_is_single_use_and_token_is_stored_only_after_photo_probe(): void
    {
        config([
            'app.env' => 'production', 'app.url' => 'https://mskba.ru',
            'vk.wall.group_id' => 174334075, 'vk.wall.mini_app_id' => 54729290,
            'vk.wall.mini_app_secret' => 'app-secret', 'vk.wall.community_token' => 'community-secret',
        ]);
        $this->actingAs($this->admin());
        Http::fake([
            'https://oauth.vk.com/access_token' => Http::response(['access_token' => 'photo-secret', 'expires_in' => 86400]),
            'https://api.vk.com/method/photos.getWallUploadServer' => Http::response(['response' => ['upload_url' => 'https://upload.vk.com/photo']]),
        ]);
        $redirect = $this->post(route('backoffice.vk-publisher.authorize'))->assertRedirect();
        parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $params);
        $this->assertSame('oauth.vk.com', parse_url($redirect->headers->get('Location'), PHP_URL_HOST));
        $this->assertSame('https://oauth.vk.com/blank.html', $params['redirect_uri']);
        $this->assertSame('photos', $params['scope']);
        $this->assertSame('code', $params['response_type']);
        $this->assertArrayNotHasKey('code_challenge', $params);
        $this->assertArrayNotHasKey('code_challenge_method', $params);
        $this->assertArrayNotHasKey('client_secret', $params);
        $flow = session('vk_publisher_oauth');
        $this->assertSame($flow['state'], $params['state']);
        $this->assertArrayNotHasKey('verifier', $flow);
        $this->assertSame('https://oauth.vk.com/blank.html', $flow['redirect']);
        $this->post(route('backoffice.vk-publisher.complete'), ['authorization_response' => 'https://oauth.vk.ru/blank.html?'.http_build_query(['state' => $flow['state'], 'code' => 'abcdef0123456789'])])
            ->assertRedirect('/backoffice/social-integrations')->assertSessionHas('social_status');
        $this->assertSame('photo-secret', config('vk.wall.user_token'));
        $this->assertTrue(config('vk.wall.user_token_expires_at') > now()->timestamp);
        $this->assertTrue(app(SocialIntegrationSettings::class)->display('vk')['mini_app_secret']);
        $this->assertStringNotContainsString('photo-secret', DB::table('social_integration_settings')->first()->encrypted_settings);
        $this->post(route('backoffice.vk-publisher.complete'), ['authorization_response' => 'https://oauth.vk.ru/blank.html?'.http_build_query(['state' => $flow['state'], 'code' => 'abcdef0123456789'])])
            ->assertSessionHasErrors('social');
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth.vk.com/access_token'
            && $request['client_secret'] === 'app-secret'
            && $request['code'] === 'abcdef0123456789'
            && $request['redirect_uri'] === $flow['redirect']
            && ! array_key_exists('code_verifier', $request->data()));
    }

    public function test_vk_oauth_rejects_untrusted_return_url_without_exchange(): void
    {
        config([
            'app.env' => 'production', 'app.url' => 'https://mskba.ru',
            'vk.wall.group_id' => 174334075, 'vk.wall.mini_app_id' => 54729290,
            'vk.wall.mini_app_secret' => 'app-secret', 'vk.wall.community_token' => 'community-secret',
        ]);
        $this->actingAs($this->admin());
        Http::fake();
        foreach (['https://example.com/blank.html?', 'https://oauth.vk.com/blank.html#', 'https://oauth.vk.com/blank.html?access_token=secret&'] as $prefix) {
            $this->post(route('backoffice.vk-publisher.authorize'))->assertRedirect();
            $flow = session('vk_publisher_oauth');
            $this->post(route('backoffice.vk-publisher.complete'), [
                'authorization_response' => $prefix.http_build_query(['state' => $flow['state'], 'code' => 'abcdef0123456789']),
            ])->assertSessionHasErrors('social');
        }
        Http::assertNothingSent();
    }

    public function test_normal_user_cannot_change_social_settings(): void
    {
        $user = User::factory()->create(['system_role' => UserSystemRoleEnum::USER, 'status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($user)->post(route('backoffice.social-integrations.save', 'vk'), [
            'community_url' => 'https://vk.ru/mskba_official',
            'community_token' => 'secret-value',
            'enabled' => '0',
            'password' => 'wrong',
        ])->assertForbidden();
        $this->assertDatabaseCount('social_integration_settings', 0);
    }

    public function test_admin_can_save_vk_token_but_cannot_enable_live_publishing_on_staging(): void
    {
        config(['app.env' => 'staging', 'app.url' => 'https://dev.mskba.ru']);
        $this->actingAs($this->admin());
        Http::fake();
        $payload = [
            'community_url' => 'https://vk.ru/mskba_official',
            'group_id' => '12345',
            'community_token' => 'secret-value',
            'enabled' => '0',
            'password' => 'test-password',
        ];
        $this->post(route('backoffice.social-integrations.save', 'vk'), $payload)->assertSessionHas('social_status');
        $this->assertSame('secret-value', config('vk.wall.community_token'));
        $this->assertFalse(config('vk.wall.enabled'));
        $this->get('/backoffice/social-integrations')->assertOk()->assertDontSee('secret-value');
        $this->post(route('backoffice.social-integrations.save', 'vk'), [
            ...$payload, 'enabled' => '1',
        ])->assertSessionHasErrors('social');
        Http::assertNothingSent();
    }
}
