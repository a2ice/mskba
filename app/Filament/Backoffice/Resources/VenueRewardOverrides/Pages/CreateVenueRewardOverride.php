<?php

namespace App\Filament\Backoffice\Resources\VenueRewardOverrides\Pages;

use App\Filament\Backoffice\Resources\VenueRewardOverrides\VenueRewardOverrideResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateVenueRewardOverride extends CreateRecord
{
    protected static string $resource = VenueRewardOverrideResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return VenueRewardOverrideResource::prepareData($data);
    }
}
