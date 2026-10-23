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
