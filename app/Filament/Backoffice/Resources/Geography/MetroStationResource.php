<?php

namespace App\Filament\Backoffice\Resources\Geography;

use App\Filament\Backoffice\Resources\Geography\Pages\CreateMetroStation;
use App\Filament\Backoffice\Resources\Geography\Pages\EditMetroStation;
use App\Filament\Backoffice\Resources\Geography\Pages\ListMetroStation;
use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Location\Domain\Models\MetroStation;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

final class MetroStationResource extends Resource
{
    protected static ?string $model = MetroStation::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Контент';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';
    protected static ?int $navigationSort = 7;
    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string { return 'Станции метро'; }
    public static function getModelLabel(): string { return 'станция метро'; }
    public static function getPluralModelLabel(): string { return 'станции метро'; }

    public static function canViewAny(): bool { return VenueResource::authorized(); }
    public static function canView(Model $record): bool { return $record instanceof MetroStation && self::canViewAny(); }
    public static function canCreate(): bool { return self::canViewAny(); }
    public static function canEdit(Model $record): bool { return self::canView($record); }
    public static function canDelete(Model $record): bool
    {
        return $record instanceof MetroStation && self::canViewAny() && ! $record->locations()->exists();
    }
    public static function canDeleteAny(): bool { return false; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Станции метро')->columns(2)->schema([
                Select::make('metro_line_id')->label('Линия')->required()->relationship('line', 'name')->searchable()->preload(),
TextInput::make('name')->label('Название станции')->required()->maxLength(255)
    ->rule(fn (?MetroStation $record, Get $get) => Rule::unique('metro_stations', 'name')->where('metro_line_id', $get('metro_line_id'))->ignore($record?->id)),
TextInput::make('latitude')->label('Широта')->numeric()->minValue(-90)->maxValue(90),
TextInput::make('longitude')->label('Долгота')->numeric()->minValue(-180)->maxValue(180),
TextInput::make('sort_order')->label('Порядок')->numeric()->minValue(0),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Станция')->searchable()->sortable(),
TextColumn::make('line.name')->label('Линия')->sortable(),
TextColumn::make('latitude')->label('Широта'),
TextColumn::make('longitude')->label('Долгота'),
TextColumn::make('locations_count')->counts('locations')->label('Привязок'),
        ])->recordActions([EditAction::make(), DeleteAction::make()->requiresConfirmation()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMetroStation::route('/'),
            'create' => CreateMetroStation::route('/create'),
            'edit' => EditMetroStation::route('/{record}/edit'),
        ];
    }
}
