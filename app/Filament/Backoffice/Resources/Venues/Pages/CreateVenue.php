<?php

namespace App\Filament\Backoffice\Resources\Venues\Pages;

use App\Filament\Backoffice\Resources\Venues\VenueBackofficeEditor;
use App\Filament\Backoffice\Resources\Venues\VenueResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class CreateVenue extends CreateRecord
{
    protected static string $resource = VenueResource::class;

    /**
     * Show errors for other tabs as well, rather than making Create/Save
     * appear to do nothing when the invalid field is not currently visible.
     */
    protected function beforeValidate(): void
    {
        // Diagnostic breadcrumbs only: never log the submitted form data.
        Log::info('backoffice.venue.create.attempt', ['actor_id' => auth('web')->id()]);
    }

    protected function onValidationError(ValidationException $exception): void
    {
        $message = collect($exception->errors())->flatten()->first();

        Log::notice('backoffice.venue.create.validation_failed', [
            'actor_id' => auth('web')->id(),
            'fields' => array_keys($exception->errors()),
            'reason' => is_string($message) ? $message : null,
        ]);

        \Filament\Notifications\Notification::make()
            ->warning()
            ->title('Не удалось сохранить площадку')
            ->body(is_string($message) && $message !== '' ? $message : 'Проверьте поля на всех вкладках.')
            ->persistent()
            ->send();
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(VenueBackofficeEditor::class)->save($data);
        } catch (ValidationException $exception) {
            // Domain validation from the editor is raised after Filament's
            // schema validation and does not invoke onValidationError itself.
            $this->onValidationError($exception);

            throw $exception;
        }
    }
}
