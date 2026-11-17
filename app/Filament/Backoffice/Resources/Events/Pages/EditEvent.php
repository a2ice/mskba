<?php

namespace App\Filament\Backoffice\Resources\Events\Pages;

use App\Filament\Backoffice\Resources\Events\EventBackofficeEditor;
use App\Filament\Backoffice\Resources\Events\EventResource;
use App\Modules\Event\Domain\Models\Event;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditEvent extends EditRecord
{
    protected static string $resource = EventResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Event $event */
        $event = $this->getRecord();

        return array_replace($data, app(EventBackofficeEditor::class)->fields($event));
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Event $record */
        return app(EventBackofficeEditor::class)->updateEvent($record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make(), RestoreAction::make()];
    }
}
