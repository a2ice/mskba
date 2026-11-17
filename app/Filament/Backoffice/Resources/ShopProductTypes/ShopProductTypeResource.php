<?php

namespace App\Filament\Backoffice\Resources\ShopProductTypes;

use App\Filament\Backoffice\Resources\ShopProductTypes\Pages\CreateShopProductType;
use App\Filament\Backoffice\Resources\ShopProductTypes\Pages\EditShopProductType;
use App\Filament\Backoffice\Resources\ShopProductTypes\Pages\ListShopProductTypes;
use App\Modules\Shop\Domain\Models\ShopAttribute;
use App\Modules\Shop\Domain\Models\ShopProductType;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class ShopProductTypeResource extends Resource
{
    protected static ?string $model = ShopProductType::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Магазин';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return 'Типы товаров';
    }

    public static function getModelLabel(): string
    {
        return 'тип товара';
    }

    public static function getPluralModelLabel(): string
    {
        return 'типы товаров';
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
        return self::canViewAny() && $record instanceof ShopProductType
            && ! $record->products()->exists() && ! $record->defaultCategories()->exists();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Тип товара')->columns(2)->schema([
                TextInput::make('name')->label('Название')->required()->maxLength(180)
                    ->live(onBlur: true)->afterStateUpdated(fn ($state, Set $set, ?ShopProductType $record) => $record === null
                        ? $set('code', Str::slug((string) $state)) : null),
                TextInput::make('code')->label('Код')->required()->maxLength(100)
                    ->regex('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D')->unique(ignoreRecord: true),
                TextInput::make('sort_order')->label('Порядок')->integer()->numeric()->minValue(0)->default(100),
                Textarea::make('description')->label('Описание')->rows(3)->columnSpanFull(),
            ]),
            Section::make('Набор характеристик')->description(
                'Общий справочник характеристик используется повторно в разных типах. '
                .'У товара характеристики определяются одним выбранным типом — независимо от дерева категорий.'
            )->schema([
                Select::make('attribute_ids')->label('Характеристики типа')->multiple()
                    ->searchable()->preload()
                    ->options(fn (): array => ShopAttribute::query()->orderBy('sort_order')->orderBy('name')
                        ->pluck('name', 'id')->all())
                    ->helperText('Новые характеристики добавляются в разделе «Магазин → Характеристики».'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort_order')->columns([
            TextColumn::make('name')->label('Тип товара')->searchable()->sortable(),
            TextColumn::make('code')->label('Код')->searchable(),
            TextColumn::make('attributes_count')->label('Характеристик')->counts('attributes'),
            TextColumn::make('products_count')->label('Товаров')->counts('products'),
            TextColumn::make('default_categories_count')->label('Категорий по умолчанию')->counts('defaultCategories'),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShopProductTypes::route('/'),
            'create' => CreateShopProductType::route('/create'),
            'edit' => EditShopProductType::route('/{record}/edit'),
        ];
    }
}
