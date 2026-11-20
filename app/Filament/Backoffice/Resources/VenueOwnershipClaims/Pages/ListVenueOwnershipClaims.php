<?php

namespace App\Filament\Backoffice\Resources\VenueOwnershipClaims\Pages;

use App\Filament\Backoffice\Resources\VenueOwnershipClaims\VenueOwnershipClaimResource;
use Filament\Resources\Pages\ListRecords;

final class ListVenueOwnershipClaims extends ListRecords
{
    protected static string $resource = VenueOwnershipClaimResource::class;
}
