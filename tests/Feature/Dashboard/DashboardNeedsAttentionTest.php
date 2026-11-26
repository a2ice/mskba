<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Application\Providers\NeedsAttentionProvider;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardNeedsAttentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_actions_depend_on_unresolved_state_not_notifications(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::UNCONFIRMED,
            'personal_data_distribution_required_at' => now(),
            'personal_data_distribution_setup_completed_at' => null,
        ]);
        $provider = app(NeedsAttentionProvider::class);
        $data = $provider->getData($user, ['limit' => 1]);
        $this->assertSame(2, $data->total);
        $this->assertSame(['account_confirmation'], array_column($data->items, 'kind'));
        $user->forceFill(['status' => UserStatusEnum::CONFIRMED])->save();
        $this->assertSame(['privacy_setup'], array_column($provider->getData($user->refresh(), [])->items, 'kind'));
        $user->forceFill(['personal_data_distribution_setup_completed_at' => now()])->save();
        $this->assertSame(0, $provider->getData($user->refresh(), [])->total);
        $user->forceFill(['status' => UserStatusEnum::BLOCKED])->save();
        $this->assertFalse($provider->isAvailable($user->refresh(), []));
    }
}
