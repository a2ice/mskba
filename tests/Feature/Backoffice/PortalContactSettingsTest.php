<?php

namespace Tests\Feature\Backoffice;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Portal\Application\Services\PortalContactSettings;
use App\Modules\Portal\Application\Services\SocialIntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PortalContactSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['system_role' => UserSystemRoleEnum::SUPERADMIN, 'status' => UserStatusEnum::CONFIRMED]);
    }

    private function payload(): array
    {
        return ['email' => 'public@example.test', 'phone' => '+7 (900) 123-45-67',
            'telegram_url' => 'https://t.me/test_mskba', 'vk_url' => 'https://vk.com/test_mskba',
            'email_public' => '1', 'phone_public' => '1', 'telegram_public' => '1', 'vk_public' => '1'];
    }

    public function test_admin_manages_four_channels_without_touching_technical_settings(): void
    {
        config(['support.email' => 'original@example.test', 'legal.privacy_email' => 'legal@example.test',
            'telegram.bot_token' => 'old-token', 'vk.wall.community_token' => 'old-vk-token']);
        $admin = $this->admin();
        $settings = app(PortalContactSettings::class);
        $this->assertSame('original@example.test', $settings->publicEmail()['value']);
        $this->actingAs($admin)->get('/backoffice/portal-contacts')->assertOk()->assertSee('Контакты портала');
        $this->post(route('backoffice.portal-contacts.save'), $this->payload())->assertRedirect('/backoffice/portal-contacts');
        $public = $settings->publicByChannel();
        $this->assertSame(['email','phone','telegram','vk'], array_keys($public));
        $this->assertSame('tel:+79001234567', $public['phone']['href']);
        $this->assertSame('https://t.me/test_mskba', config('services.social.telegram_url'));
        $this->assertSame('https://vk.com/test_mskba', config('services.social.vk_url'));
        $this->assertSame('old-token', config('telegram.bot_token'));
        $this->assertSame('old-vk-token', config('vk.wall.community_token'));
        $this->assertSame('legal@example.test', config('legal.privacy_email'));
        $this->assertSame('original@example.test', config('support.email'));

        $this->post(route('backoffice.portal-contacts.save'), [...$this->payload(),
            'email_public'=>'0', 'phone_public'=>'0', 'telegram_public'=>'0', 'vk_public'=>'0'])->assertRedirect();
        $this->assertSame([], $settings->publicContacts());
        $this->assertSame('public@example.test', $settings->adminValues($admin)['email']);
        app(SocialIntegrationSettings::class)->save('telegram', ['community_url'=>'https://t.me/changed_mskba'], $admin->id);
        $this->assertSame('https://t.me/changed_mskba', $settings->adminValues($admin)['telegram_url']);
        $this->assertSame([], $settings->publicContacts());
    }

    public function test_normal_user_cannot_edit_or_read_admin_settings_and_bad_urls_are_rejected(): void
    {
        $user = User::factory()->create(['system_role' => UserSystemRoleEnum::USER, 'status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($user)->post(route('backoffice.portal-contacts.save'), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('portal_contact_settings', 0);
        $this->actingAs($this->admin())->post(route('backoffice.portal-contacts.save'), [...$this->payload(),
            'telegram_url'=>'https://evil.example/t.me'])->assertSessionHasErrors('telegram_url');
        $this->assertDatabaseCount('portal_contact_settings', 0);
    }
}
