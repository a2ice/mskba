<?php

namespace App\Filament\Backoffice\Resources\Geography;

use App\Filament\Backoffice\Resources\Geography\Pages\CreateDistrict;
use App\Filament\Backoffice\Resources\Geography\Pages\EditDistrict;
use App\Filament\Backoffice\Resources\Geography\Pages\ListDistrict;
use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Location\Domain\Models\District;
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

final class DistrictResource extends Resource
{
    protected static ?string $model = District::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Контент';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map';
    protected static ?int $navigationSort = 5;
    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string { return 'Районы'; }
    public static function getModelLabel(): string { return 'район'; }
    public static function getPluralModelLabel(): string { return 'районы'; }

    public static function canViewAny(): bool { return VenueResource::authorized(); }
    public static function canView(Model $record): bool { return $record instanceof District && self::canViewAny(); }
    public static function canCreate(): bool { return self::canViewAny(); }
    public static function canEdit(Model $record): bool { return self::canView($record); }
    public static function canDelete(Model $record): bool
    {
        return $record instanceof District && self::canViewAny() && ! $record->addresses()->exists();
    }
    public static function canDeleteAny(): bool { return false; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Районы')->columns(2)->schema([
                Select::make('city_id')->label('Город')->required()->relationship('city', 'name')->searchable()->preload(),
TextInput::make('name')->label('Название')->required()->maxLength(255)
    ->rule(fn (?District $record, Get $get) => Rule::unique('districts', 'name')->where('city_id', $get('city_id'))->ignore($record?->id)),
TextInput::make('alias')->label('Код')->required()->maxLength(255)
    ->regex('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D')
    ->rule(fn (?District $record, Get $get) => Rule::unique('districts', 'alias')->where('city_id', $get('city_id'))->ignore($record?->id)),
TextInput::make('short_name')->label('Сокращение')->maxLength(255),
Textarea::make('description')->label('Описание')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Район')->searchable()->sortable(),
TextColumn::make('city.name')->label('Город')->sortable(),
TextColumn::make('alias')->label('Код'),
TextColumn::make('addresses_count')->counts('addresses')->label('Адресов'),
        ])->recordActions([EditAction::make(), DeleteAction::make()->requiresConfirmation()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDistrict::route('/'),
            'create' => CreateDistrict::route('/create'),
            'edit' => EditDistrict::route('/{record}/edit'),
        ];
    }
}
