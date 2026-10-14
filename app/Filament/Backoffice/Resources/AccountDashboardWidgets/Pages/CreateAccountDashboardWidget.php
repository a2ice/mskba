<?php
namespace App\Filament\Backoffice\Resources\AccountDashboardWidgets\Pages;

use App\Filament\Backoffice\Resources\AccountDashboardWidgets\AccountDashboardWidgetResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateAccountDashboardWidget extends CreateRecord
{
    protected static string $resource = AccountDashboardWidgetResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        AccountDashboardWidgetResource::assertSafeData($data);
        return $data;
    }
}
