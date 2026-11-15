<?php

namespace App\Filament\Backoffice\Resources\VenueRentalBookings\Pages;

use App\Filament\Backoffice\Resources\VenueRentalBookings\VenueRentalBookingResource;
use Filament\Resources\Pages\ListRecords;

final class ListVenueRentalBookings extends ListRecords
{
    protected static string $resource = VenueRentalBookingResource::class;
}
