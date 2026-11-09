<?php

namespace App\Filament\Backoffice\Resources\ShopAttributes;

use App\Filament\Backoffice\Resources\ShopAttributes\Pages\CreateShopAttribute;
use App\Filament\Backoffice\Resources\ShopAttributes\Pages\EditShopAttribute;
use App\Filament\Backoffice\Resources\ShopAttributes\Pages\ListShopAttributes;
use App\Modules\Shop\Domain\Models\ShopAttribute;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class ShopAttributeResource extends Resource
{
    protected static ?string $model = ShopAttribute::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Магазин';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return 'Характеристики';
    }

    public static function getModelLabel(): string
    {
        return 'характеристика';
    }

    public static function getPluralModelLabel(): string
    {
        return 'характеристики';
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('manage-content');
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return self::canViewAny() && $record instanceof ShopAttribute
            && ! $record->categories()->exists() && ! $record->productValues()->exists();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Характеристика')->columns(2)->schema([
                TextInput::make('name')->label('Название')->required()->maxLength(150)
                    ->live(onBlur: true)->afterStateUpdated(fn ($state, Set $set, ?ShopAttribute $record) => $record === null ? $set('code', Str::slug((string) $state)) : null),
                TextInput::make('code')->label('Код')->required()->maxLength(100)
                    ->regex('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D')->unique(ignoreRecord: true),
                Select::make('type')->label('Тип данных')->options([
                    'text' => 'Текст', 'number' => 'Число',
                    'boolean' => 'Да / нет (1 / 0)', 'select' => 'Выбор из списка',
                ])->required()->default('text')->live()->native(false),
                TextInput::make('unit')->label('Единица измерения')->maxLength(40),
                TagsInput::make('options')->label('Допустимые значения')
                    ->helperText('Каждое значение добавляется отдельно. Используются только при типе «Выбор из списка».')
                    ->visible(fn (Get $get): bool => $get('type') === 'select')->columnSpanFull(),
                TextInput::make('sort_order')->label('Порядок')->numeric()->integer()
                    ->minValue(0)->default(100)->required(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort_order')->columns([
            TextColumn::make('name')->label('Характеристика')->searchable(),
            TextColumn::make('code')->label('Код')->searchable(),
            TextColumn::make('type')->label('Тип'),
            TextColumn::make('unit')->label('Ед. изм.'),
            TextColumn::make('categories_count')->counts('categories')->label('Категорий'),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListShopAttributes::route('/'),
            'create' => CreateShopAttribute::route('/create'),
            'edit' => EditShopAttribute::route('/{record}/edit')];
    }
}
