<?php

namespace App\Modules\Dashboard\Application\Contracts;

use App\Modules\Dashboard\Application\Data\DashboardWidgetData;
use App\Modules\Identity\Domain\Models\User;

interface DashboardDataProvider
{
    /** @param array<string, mixed> $settings */
    public function isAvailable(User $user, array $settings): bool;

    /** @param array<string, mixed> $settings */
    public function getData(User $user, array $settings): DashboardWidgetData;
}
