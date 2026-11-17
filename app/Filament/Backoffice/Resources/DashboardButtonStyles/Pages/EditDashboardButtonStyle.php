<?php
namespace App\Filament\Backoffice\Resources\DashboardButtonStyles\Pages;

use App\Filament\Backoffice\Resources\DashboardButtonStyles\DashboardButtonStyleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditDashboardButtonStyle extends EditRecord
{
    protected static string $resource = DashboardButtonStyleResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        DashboardButtonStyleResource::validateStyle($data);

        return $data;
    }
}
