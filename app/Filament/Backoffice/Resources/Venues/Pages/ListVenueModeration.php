<?php

namespace App\Filament\Backoffice\Resources\Venues\Pages;

use App\Filament\Backoffice\Resources\Venues\VenueModerationResource;
use Filament\Resources\Pages\ListRecords;

final class ListVenueModeration extends ListRecords
{
    protected static string $resource = VenueModerationResource::class;
}
