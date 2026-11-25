<?php

namespace App\Modules\Dashboard\Application\Services;

use App\Modules\Dashboard\Application\Contracts\DashboardDataProvider;
use App\Modules\Dashboard\Application\Data\DashboardWidgetData;
use App\Modules\Dashboard\Application\Providers\UnreadMessagesProvider;
use App\Modules\Dashboard\Application\Providers\MyInterestsProvider;
use App\Modules\Dashboard\Application\Providers\WalletSummaryProvider;
use App\Modules\Dashboard\Application\Providers\UpcomingEventsProvider;
use App\Modules\Dashboard\Application\Providers\MyRentalRequestsProvider;
use App\Modules\Dashboard\Domain\Models\AccountDashboardWidget;
use App\Modules\Identity\Domain\Models\User;

final class DashboardDataRegistry
{
    /** @var array<string, class-string<DashboardDataProvider>> */
    private const PROVIDERS = [
        'unread_messages' => UnreadMessagesProvider::class,
        'my_interests' => MyInterestsProvider::class,
        'wallet_summary' => WalletSummaryProvider::class,
        'upcoming_events' => UpcomingEventsProvider::class,
        'my_rental_requests' => MyRentalRequestsProvider::class,
    ];

    private function resolve(AccountDashboardWidget $widget): ?DashboardDataProvider
    {
        $key = $widget->source_key;
        $class = is_string($key) && $widget->kind === 'dynamic' ? (self::PROVIDERS[$key] ?? null) : null;

        return $class === null ? null : app($class);
    }

    public function isAvailable(AccountDashboardWidget $widget, User $user): bool
    {
        $user = $user->canonical();
        if ($user->isBlocked()) {
            return false;
        }

        return $this->resolve($widget)?->isAvailable($user, $widget->source_settings ?? []) ?? false;
    }

    public function getData(AccountDashboardWidget $widget, User $user): DashboardWidgetData
    {
        $user = $user->canonical();
        $provider = $this->resolve($widget);
        abort_unless($provider !== null && $this->isAvailable($widget, $user), 404);

        return $provider->getData($user, $widget->source_settings ?? []);
    }
}
