<?php
namespace App\Filament\Backoffice\Resources\AccountDashboardWidgets\Pages;

use App\Filament\Backoffice\Resources\AccountDashboardWidgets\AccountDashboardWidgetResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditAccountDashboardWidget extends EditRecord
{
    protected static string $resource = AccountDashboardWidgetResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        AccountDashboardWidgetResource::assertSafeData($data, $this->record);
        return $data;
    }
}
