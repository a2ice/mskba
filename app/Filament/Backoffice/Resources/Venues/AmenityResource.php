<?php

namespace App\Filament\Backoffice\Resources\Venues;

use App\Filament\Backoffice\Resources\Venues\Pages\CreateAmenity;
use App\Filament\Backoffice\Resources\Venues\Pages\EditAmenity;
use App\Filament\Backoffice\Resources\Venues\Pages\ListAmenities;
use App\Modules\Venue\Domain\Models\Amenity;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class AmenityResource extends Resource
{
    protected static ?string $model = Amenity::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Контент';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-check-badge';
    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string { return 'Удобства площадок'; }
    public static function getModelLabel(): string { return 'удобство'; }
    public static function getPluralModelLabel(): string { return 'удобства площадок'; }

    public static function canViewAny(): bool { return VenueResource::authorized(); }
    public static function canView(Model $record): bool { return $record instanceof Amenity && self::canViewAny(); }
    public static function canCreate(): bool { return self::canViewAny(); }
    public static function canEdit(Model $record): bool { return self::canView($record); }
    public static function canDelete(Model $record): bool
    {
        return $record instanceof Amenity && self::canViewAny() && ! $record->venues()->exists();
    }
    public static function canDeleteAny(): bool { return false; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Удобство')->columns(2)->schema([
                TextInput::make('name')->label('Название')->required()->maxLength(120),
                TextInput::make('alias')->label('Код (alias)')->required()->maxLength(120)
                    ->regex('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D')->unique(ignoreRecord: true),
                Textarea::make('description')->label('Описание')->columnSpanFull(),
                TextInput::make('icon')->label('Код иконки')->maxLength(255),
                Select::make('applies_to')->label('Для типа площадки')->required()->default('all')
                    ->options(['all' => 'Для всех', 'indoor' => 'В помещении', 'outdoor' => 'На улице']),
                TextInput::make('sort_order')->label('Порядок')->numeric()->minValue(0)->default(0),
                Toggle::make('is_active')->label('Активно')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Удобство')->searchable()->sortable(),
            TextColumn::make('alias')->label('Код')->searchable(),
            TextColumn::make('applies_to')->label('Применимость')
                ->formatStateUsing(fn (string $state): string => match ($state) {
                    'indoor' => 'В помещении', 'outdoor' => 'На улице', default => 'Все',
                }),
            IconColumn::make('is_active')->label('Активно')->boolean(),
            TextColumn::make('venues_count')->counts('venues')->label('Площадок'),
        ])->recordActions([EditAction::make(), DeleteAction::make()->requiresConfirmation()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAmenities::route('/'),
            'create' => CreateAmenity::route('/create'),
            'edit' => EditAmenity::route('/{record}/edit'),
        ];
    }
}
