<?php

namespace App\Modules\Dashboard\Application\Providers;

use App\Modules\Dashboard\Application\Contracts\DashboardDataProvider;
use App\Modules\Dashboard\Application\Data\DashboardWidgetData;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\UseCases\CountNewUserNotificationsHandler;

final readonly class UnreadMessagesProvider implements DashboardDataProvider
{
    public function __construct(private CountNewUserNotificationsHandler $notifications) {}

    public function isAvailable(User $user, array $settings): bool
    {
        return ! $user->canonical()->isBlocked();
    }

    public function getData(User $user, array $settings): DashboardWidgetData
    {
        abort_unless($this->isAvailable($user, $settings), 404);

        return new DashboardWidgetData($this->notifications->handle($user->canonical()));
    }
}
