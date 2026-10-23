<?php

namespace App\Filament\Backoffice\Resources\Geography;

use App\Filament\Backoffice\Resources\Geography\Pages\CreateMetroLine;
use App\Filament\Backoffice\Resources\Geography\Pages\EditMetroLine;
use App\Filament\Backoffice\Resources\Geography\Pages\ListMetroLine;
use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Location\Domain\Models\MetroLine;
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

final class MetroLineResource extends Resource
{
    protected static ?string $model = MetroLine::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Контент';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';
    protected static ?int $navigationSort = 6;
    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string { return 'Линии метро'; }
    public static function getModelLabel(): string { return 'линия метро'; }
    public static function getPluralModelLabel(): string { return 'линии метро'; }

    public static function canViewAny(): bool { return VenueResource::authorized(); }
    public static function canView(Model $record): bool { return $record instanceof MetroLine && self::canViewAny(); }
    public static function canCreate(): bool { return self::canViewAny(); }
    public static function canEdit(Model $record): bool { return self::canView($record); }
    public static function canDelete(Model $record): bool
    {
        return $record instanceof MetroLine && self::canViewAny() && ! $record->stations()->exists();
    }
    public static function canDeleteAny(): bool { return false; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Линии метро')->columns(2)->schema([
                TextInput::make('name')->label('Название линии')->required()->maxLength(255)->unique(ignoreRecord: true),
TextInput::make('color')->label('Цвет линии (HEX без #)')->maxLength(32)->regex('/\A[0-9A-Fa-f]{6}\z/D'),
TextInput::make('sort_order')->label('Порядок')->numeric()->minValue(0),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Линия')->searchable()->sortable(),
TextColumn::make('color')->label('HEX')->copyable(),
TextColumn::make('sort_order')->label('Порядок')->sortable(),
TextColumn::make('stations_count')->counts('stations')->label('Станций'),
        ])->recordActions([EditAction::make(), DeleteAction::make()->requiresConfirmation()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMetroLine::route('/'),
            'create' => CreateMetroLine::route('/create'),
            'edit' => EditMetroLine::route('/{record}/edit'),
        ];
    }
}
