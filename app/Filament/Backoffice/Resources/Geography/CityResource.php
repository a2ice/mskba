<?php

namespace App\Filament\Backoffice\Resources\Geography;

use App\Filament\Backoffice\Resources\Geography\Pages\CreateCity;
use App\Filament\Backoffice\Resources\Geography\Pages\EditCity;
use App\Filament\Backoffice\Resources\Geography\Pages\ListCity;
use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Location\Domain\Models\City;
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

final class CityResource extends Resource
{
    protected static ?string $model = City::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Контент';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';
    protected static ?int $navigationSort = 4;
    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string { return 'Города'; }
    public static function getModelLabel(): string { return 'город'; }
    public static function getPluralModelLabel(): string { return 'города'; }

    public static function canViewAny(): bool { return VenueResource::authorized(); }
    public static function canView(Model $record): bool { return $record instanceof City && self::canViewAny(); }
    public static function canCreate(): bool { return self::canViewAny(); }
    public static function canEdit(Model $record): bool { return self::canView($record); }
    public static function canDelete(Model $record): bool
    {
        return $record instanceof City && self::canViewAny() && ! $record->districts()->exists() && ! $record->addresses()->exists();
    }
    public static function canDeleteAny(): bool { return false; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Города')->columns(2)->schema([
                TextInput::make('name')->label('Название')->required()->maxLength(255)->unique(ignoreRecord: true),
TextInput::make('alias')->label('Код')->required()->maxLength(255)->regex('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D')->unique(ignoreRecord: true),
TextInput::make('short_name')->label('Сокращение')->maxLength(255),
Textarea::make('description')->label('Описание')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Город')->searchable()->sortable(),
TextColumn::make('alias')->label('Код')->searchable(),
TextColumn::make('districts_count')->counts('districts')->label('Районов'),
TextColumn::make('addresses_count')->counts('addresses')->label('Адресов'),
        ])->recordActions([EditAction::make(), DeleteAction::make()->requiresConfirmation()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCity::route('/'),
            'create' => CreateCity::route('/create'),
            'edit' => EditCity::route('/{record}/edit'),
        ];
    }
}
