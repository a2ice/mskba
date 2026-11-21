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
