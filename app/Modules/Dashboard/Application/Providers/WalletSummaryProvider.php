<?php

namespace App\Modules\Dashboard\Application\Providers;

use App\Modules\Dashboard\Application\Contracts\DashboardDataProvider;
use App\Modules\Dashboard\Application\Data\DashboardWidgetData;
use App\Modules\Finance\Application\Queries\GetAccountWalletOverview;
use App\Modules\Identity\Domain\Models\User;

final readonly class WalletSummaryProvider implements DashboardDataProvider
{
    public function __construct(private GetAccountWalletOverview $wallet) {}

    public function isAvailable(User $user, array $settings): bool
    {
        $user = $user->canonical();

        return ! $user->isBlocked() && ! $user->trashed();
    }

    public function getData(User $user, array $settings): DashboardWidgetData
    {
        $user = $user->canonical();
        abort_unless($this->isAvailable($user, $settings), 404);
        $limit = max(1, min(10, is_numeric($settings['limit'] ?? null) ? (int) $settings['limit'] : 5));
        $overview = $this->wallet->handle($user, $limit);

        $items = array_map(static fn (array $op): array => [
            'id' => $op['id'],
            'title' => $op['label'],
            'completed_at' => $op['completedAt']?->toIso8601String(),
            'total_delta_minor' => $op['totalDeltaMinor'],
        ], $overview['operations']);

        return new DashboardWidgetData(count($items), $items, [
            'wallet_exists' => $overview['walletExists'],
            'currency' => $overview['currency'],
            'total_balance_minor' => $overview['totalBalanceMinor'],
            'real_balance_minor' => $overview['realBalanceMinor'],
            'bonus_balance_minor' => $overview['bonusBalanceMinor'],
            'url' => route('account.wallet', [], false),
        ]);
    }
}
