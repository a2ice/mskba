<?php

namespace App\Modules\Dashboard\Application\Services;

use App\Modules\Dashboard\Application\Contracts\DashboardDataProvider;
use App\Modules\Dashboard\Application\Data\DashboardWidgetData;
use App\Modules\Dashboard\Application\Providers\UnreadMessagesProvider;
use App\Modules\Dashboard\Application\Providers\MyInterestsProvider;
use App\Modules\Dashboard\Application\Providers\WalletSummaryProvider;
use App\Modules\Dashboard\Application\Providers\UpcomingEventsProvider;
use App\Modules\Dashboard\Application\Providers\MyRentalRequestsProvider;
use App\Modules\Dashboard\Application\Providers\NeedsAttentionProvider;
use App\Modules\Dashboard\Application\Providers\VenueRentalInboxProvider;
use App\Modules\Dashboard\Domain\Models\AccountDashboardWidget;
use App\Modules\Identity\Domain\Models\User;

final class DashboardDataRegistry
{
    /** @var array<string, array{provider: class-string<DashboardDataProvider>, label: string, limit: bool}> */
    private const PROVIDERS = [
        'unread_messages' => ['provider' => UnreadMessagesProvider::class, 'label' => 'Непрочитанные сообщения', 'limit' => false],
        'my_interests' => ['provider' => MyInterestsProvider::class, 'label' => 'Мои интересы', 'limit' => true],
        'wallet_summary' => ['provider' => WalletSummaryProvider::class, 'label' => 'Мои финансы', 'limit' => true],
        'upcoming_events' => ['provider' => UpcomingEventsProvider::class, 'label' => 'Моё расписание', 'limit' => true],
        'my_rental_requests' => ['provider' => MyRentalRequestsProvider::class, 'label' => 'Мои заявки на аренду', 'limit' => true],
        'needs_attention' => ['provider' => NeedsAttentionProvider::class, 'label' => 'Требует моего внимания', 'limit' => true],
        'venue_rental_inbox' => ['provider' => VenueRentalInboxProvider::class, 'label' => 'Входящие заявки площадок', 'limit' => true],
    ];

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_map(static fn (array $source): string => $source['label'], self::PROVIDERS);
    }

    public static function supportsLimit(mixed $source): bool
    {
        return is_string($source) && (self::PROVIDERS[$source]['limit'] ?? false);
    }

    private function resolve(AccountDashboardWidget $widget): ?DashboardDataProvider
    {
        $key = $widget->source_key;
        $class = is_string($key) && $widget->kind === 'dynamic' ? (self::PROVIDERS[$key]['provider'] ?? null) : null;

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
