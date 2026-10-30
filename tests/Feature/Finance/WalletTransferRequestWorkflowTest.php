<?php
namespace Tests\Feature\Finance;
use App\Modules\Finance\Domain\Models\WalletOperation;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class WalletTransferRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function confirmed(string $username): User
    {
        return User::factory()->create(['username' => $username, 'status' => UserStatusEnum::CONFIRMED->value]);
    }

    public function test_request_permission_defaults_on_and_can_be_revoked(): void
    {
        $requester = $this->confirmed('requester');
        $friend = $this->confirmed('friend');
        $this->assertSame(UserPrivacyVisibilityEnum::EVERYONE, UserPrivacySettingTypeEnum::WALLET_TRANSFER_REQUESTS->defaultVisibility());

        $this->actingAs($requester)->getJson(route('account.wallet.transfer-recipients', ['q' => 'friend', 'purpose' => 'request']))
            ->assertOk()->assertJsonPath('candidates.0.id', $friend->id);
        $this->actingAs($friend)->post(route('account.settings.transfer-request-privacy.update'), [])->assertRedirect();
        $this->assertDatabaseHas('user_privacy_settings', ['user_id' => $friend->id, 'type' => 'wallet_transfer_requests', 'visibility' => 'nobody']);
        $this->actingAs($requester)->getJson(route('account.wallet.transfer-recipients', ['q' => 'friend', 'purpose' => 'request']))
            ->assertOk()->assertJsonCount(0, 'candidates');

        $this->actingAs($friend)->post(route('account.settings.transfer-request-privacy.update'), ['allow_requests' => '1'])
            ->assertRedirect();
        $this->actingAs($requester)->getJson(route('account.wallet.transfer-recipients', ['q' => 'friend', 'purpose' => 'request']))
            ->assertOk()->assertJsonPath('candidates.0.id', $friend->id);
    }

    public function test_request_sends_notification_without_money_movement_and_is_rate_limited(): void
    {
        $requester = $this->confirmed('requester');
        $friend = $this->confirmed('friend');
        $key = 'wallet-transfer-request:'.$requester->id.':'.$friend->id;
        RateLimiter::clear($key);

        $this->actingAs($requester)->postJson(route('account.wallet.transfer-requests.store'), ['recipient_user_id' => $friend->id])
            ->assertCreated()->assertJsonPath('message', 'Запрос отправлен. Перевод возможен только по решению получателя.');
        $notification = UserNotification::query()->sole();
        $this->assertSame($friend->id, $notification->user_id);
        $this->assertSame(UserNotificationTypeEnum::FINANCE, $notification->type);
        $this->assertSame('finance.wallet.transfer.request', $notification->payload['source']);
        $this->assertDatabaseCount('wallet_operations', 0);
        $this->assertDatabaseCount('wallet_ledger_entries', 0);

        $this->actingAs($requester)->postJson(route('account.wallet.transfer-requests.store'), ['recipient_user_id' => $friend->id])
            ->assertStatus(429);
        $this->assertSame(1, UserNotification::query()->count());
        RateLimiter::clear($key);
    }

    public function test_direct_post_respects_opt_out_and_discoverability(): void
    {
        $requester = $this->confirmed('requester');
        $friend = $this->confirmed('friend');
        $friend->privacySettings()->updateOrCreate(
            ['type' => UserPrivacySettingTypeEnum::WALLET_TRANSFER_REQUESTS],
            ['visibility' => UserPrivacyVisibilityEnum::NOBODY]
        );
        $this->actingAs($requester)->postJson(route('account.wallet.transfer-requests.store'), ['recipient_user_id' => $friend->id])
            ->assertNotFound();
        $friend->privacySettings()->updateOrCreate(
            ['type' => UserPrivacySettingTypeEnum::WALLET_TRANSFER_REQUESTS],
            ['visibility' => UserPrivacyVisibilityEnum::EVERYONE]
        );
        $friend->privacySettings()->updateOrCreate(
            ['type' => UserPrivacySettingTypeEnum::DISCOVERABILITY],
            ['visibility' => UserPrivacyVisibilityEnum::NOBODY]
        );
        $this->actingAs($requester)->postJson(route('account.wallet.transfer-requests.store'), ['recipient_user_id' => $friend->id])
            ->assertNotFound();
        $this->assertDatabaseCount('user_notifications', 0);
    }

    public function test_light_wallet_and_venue_entry_share_real_form(): void
    {
        $before = (string) config('themes.active');
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        try {
            $user = $this->confirmed('player');
            $this->actingAs($user)->get(route('account.wallet'))
                ->assertOk()->assertSee('Доступно для оплаты сервисов MSKBA')->assertSee('data-wallet-transfer-form', false);
            $this->actingAs($user)->get(route('venues.create'))
                ->assertOk()->assertSee('data-mskba-venue-create', false)
                ->assertSee('data-address-current-location', false)
                ->assertSee('data-address-suggest-input', false)
                ->assertSee('name="court_count"', false)
                ->assertSee('name="hoops_count"', false);
        } finally {
            config()->set('themes.active', $before);
            View::replaceNamespace('theme', resource_path('themes/'.$before.'/views'));
            app()->forgetInstance(ThemeResolver::class);
        }
    }
}
