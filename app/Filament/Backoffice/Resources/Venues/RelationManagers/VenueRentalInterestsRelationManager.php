<?php

namespace App\Filament\Backoffice\Resources\Venues\RelationManagers;

use App\Filament\Backoffice\Resources\Venues\Support\VenueRentalSupportTables;
use App\Modules\VenueBooking\Application\Services\VenueBookingBackofficeAccess;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class VenueRentalInterestsRelationManager extends RelationManager
{
    protected static string $relationship = 'venueInterests';
    protected static ?string $title = 'Предзаявки';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return app(VenueBookingBackofficeAccess::class)->canView(auth('web')->user());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return VenueRentalSupportTables::interests($table);
    }
}
