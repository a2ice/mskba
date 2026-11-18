<?php

namespace App\Filament\Backoffice\Pages;

use App\Filament\Backoffice\Widgets\NavigationOverview;
use Filament\Pages\Dashboard;

final class BackofficeDashboard extends Dashboard
{
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
