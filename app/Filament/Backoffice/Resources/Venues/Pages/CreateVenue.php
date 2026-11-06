<?php

namespace App\Filament\Backoffice\Resources\Venues\Pages;

use App\Filament\Backoffice\Resources\Venues\VenueBackofficeEditor;
use App\Filament\Backoffice\Resources\Venues\VenueResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateVenue extends CreateRecord
{
    protected static string $resource = VenueResource::class;

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

    protected function handleRecordCreation(array $data): Model
    {
        return app(VenueBackofficeEditor::class)->save($data);
    }
}
