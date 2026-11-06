<?php

namespace App\Filament\Backoffice\Resources\Venues\Pages;

use App\Filament\Backoffice\Resources\Venues\VenueBackofficeEditor;
use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Venue\Domain\Models\Venue;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditVenue extends EditRecord
{
    protected static string $resource = VenueResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Venue $venue */
        $venue = $this->getRecord();

        return array_replace($data, app(VenueBackofficeEditor::class)->fields($venue));
    }

    /**
     * Show errors for other tabs as well, rather than making Create/Save
     * appear to do nothing when the invalid field is not currently visible.
     */
    protected function onValidationError(\Illuminate\Validation\ValidationException $exception): void
    {
        $message = collect($exception->errors())->flatten()->first();

        \Filament\Notifications\Notification::make()
            ->warning()
            ->title('Не удалось сохранить площадку')
            ->body(is_string($message) && $message !== '' ? $message : 'Проверьте поля на всех вкладках.')
            ->persistent()
            ->send();
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Venue $record */
        return app(VenueBackofficeEditor::class)->save($data, $record);
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }
}
