<?php
namespace App\Filament\Backoffice\Resources\AccountDashboardWidgets\Pages;

use App\Filament\Backoffice\Resources\AccountDashboardWidgets\AccountDashboardWidgetResource;
use Filament\Actions\CreateAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

final class ListAccountDashboardWidgets extends ListRecords
{
    protected static string $resource = AccountDashboardWidgetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open_account')->label('Посмотреть дашборд')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(route('account'))->openUrlInNewTab(),
            CreateAction::make()->label('Добавить виджет'),
        ];
    }
}
