<?php
namespace App\Filament\Backoffice\Resources\AccountDashboardWidgets\Pages;

use App\Filament\Backoffice\Resources\AccountDashboardWidgets\AccountDashboardWidgetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListAccountDashboardWidgets extends ListRecords
{
    protected static string $resource = AccountDashboardWidgetResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Добавить виджет')];
    }
}
