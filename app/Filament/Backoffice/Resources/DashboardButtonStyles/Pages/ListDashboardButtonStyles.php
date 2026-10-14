<?php
namespace App\Filament\Backoffice\Resources\DashboardButtonStyles\Pages;

use App\Filament\Backoffice\Resources\DashboardButtonStyles\DashboardButtonStyleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListDashboardButtonStyles extends ListRecords
{
    protected static string $resource = DashboardButtonStyleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Добавить стиль')];
    }
}
