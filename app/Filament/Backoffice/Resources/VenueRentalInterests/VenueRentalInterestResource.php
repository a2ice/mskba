<?php

namespace App\Filament\Backoffice\Resources\VenueRentalInterests;

use App\Filament\Backoffice\Resources\VenueRentalInterests\Pages\ListVenueRentalInterests;
use App\Filament\Backoffice\Resources\Venues\Support\VenueRentalSupportTables;
use App\Modules\Event\Domain\Models\EventVenueInterest;
use App\Modules\VenueBooking\Application\Services\VenueBookingBackofficeAccess;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class VenueRentalInterestResource extends Resource
{
    protected static ?string $model = EventVenueInterest::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Контент';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string { return 'Предзаявки'; }
    public static function getModelLabel(): string { return 'предзаявка'; }
    public static function getPluralModelLabel(): string { return 'предзаявки'; }

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
    public static function table(Table $table): Table { return VenueRentalSupportTables::interests($table); }

    public static function getPages(): array
    {
        return ['index' => ListVenueRentalInterests::route('/')];
    }
}
