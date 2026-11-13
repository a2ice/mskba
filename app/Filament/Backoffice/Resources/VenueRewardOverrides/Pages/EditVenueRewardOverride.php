<?php

namespace App\Filament\Backoffice\Resources\VenueRewardOverrides\Pages;

use App\Filament\Backoffice\Resources\VenueRewardOverrides\VenueRewardOverrideResource;
use Filament\Resources\Pages\EditRecord;

final class EditVenueRewardOverride extends EditRecord
{
    protected static string $resource = VenueRewardOverrideResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['amount_rub'] = ($data['amount_minor'] ?? null) === null
            ? null
            : number_format(((int) $data['amount_minor']) / 100, 2, '.', '');

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['venue_id']);

        return VenueRewardOverrideResource::prepareData($data, $this->record);
    }
}
