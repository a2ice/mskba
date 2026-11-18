<?php

namespace App\Filament\Backoffice\Pages;

use App\Filament\Backoffice\Widgets\NavigationOverview;
use Filament\Pages\Dashboard;

final class BackofficeDashboard extends Dashboard
{
    // Retain the original Filament route name (pages.dashboard) and URL (/).
    protected static ?string $slug = 'dashboard';

    protected static ?string $title = 'Дашборд';

    protected static ?string $navigationLabel = 'Дашборд';

    public function getWidgets(): array
    {
        return [NavigationOverview::class];
    }

    public function getColumns(): int | array
    {
        return 1;
    }
}
