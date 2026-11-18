<?php

namespace App\Filament\Backoffice\Resources\VenueRentalBookings;

use App\Filament\Backoffice\Resources\VenueRentalBookings\Pages\ListVenueRentalBookings;
use App\Filament\Backoffice\Resources\Venues\Support\VenueRentalSupportTables;
use App\Modules\VenueBooking\Application\Services\VenueBookingBackofficeAccess;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class VenueRentalBookingResource extends Resource
{
    protected static ?string $model = VenueBooking::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Аренда и документы';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';
    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string { return 'Заявки на аренду'; }
    public static function getModelLabel(): string { return 'заявка на аренду'; }
    public static function getPluralModelLabel(): string { return 'заявки на аренду'; }

    public static function canViewAny(): bool
    {
        return app(VenueBookingBackofficeAccess::class)->canView(auth('web')->user());
    }

    public static function canView(Model $record): bool { return self::canViewAny(); }
    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }

    public static function form(Schema $schema): Schema { return $schema->components([]); }
    public static function table(Table $table): Table { return VenueRentalSupportTables::bookings($table); }

    public static function getPages(): array
    {
        return ['index' => ListVenueRentalBookings::route('/')];
    }
}
