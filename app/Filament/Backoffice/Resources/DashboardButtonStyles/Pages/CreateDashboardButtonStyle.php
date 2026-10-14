<?php
namespace App\Filament\Backoffice\Resources\DashboardButtonStyles\Pages;

use App\Filament\Backoffice\Resources\DashboardButtonStyles\DashboardButtonStyleResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateDashboardButtonStyle extends CreateRecord
{
    protected static string $resource = DashboardButtonStyleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        DashboardButtonStyleResource::validateStyle($data);

        return $data;
    }
}
