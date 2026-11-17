<?php

namespace App\Filament\Backoffice\Resources\ShopProducts;

use App\Filament\Backoffice\Resources\ShopProducts\Pages\CreateShopProduct;
use App\Filament\Backoffice\Resources\ShopProducts\Pages\EditShopProduct;
use App\Filament\Backoffice\Resources\ShopProducts\Pages\ListShopProducts;
use App\Modules\Shop\Application\Services\ShopCatalogService;
use App\Modules\Shop\Domain\Models\ShopAttribute;
use App\Modules\Shop\Domain\Models\ShopCategory;
use App\Modules\Shop\Domain\Models\ShopProduct;
use App\Modules\Shop\Domain\Models\ShopProductType;
use App\Modules\Shop\Domain\Models\ShopTag;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ShopProductResource extends Resource
{
    protected static ?string $model = ShopProduct::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Магазин';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return 'Товары';
    }

    public static function getModelLabel(): string
    {
        return 'товар';
    }

    public static function getPluralModelLabel(): string
    {
        return 'товары';
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
        return self::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Товар')->columns(2)->schema([
                TextInput::make('name')->label('Название')->required()->maxLength(220)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, Set $set, ?ShopProduct $record) => $record === null
                        ? $set('slug', Str::slug((string) $state)) : null),
                TextInput::make('slug')->label('Адрес (slug)')->required()->maxLength(190)
                    ->regex('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D')->unique(ignoreRecord: true),
                TextInput::make('sku')->label('Артикул (SKU)')->maxLength(100)->unique(ignoreRecord: true),
                Select::make('status')->label('Публикация')
                    ->options(['draft' => 'Черновик', 'published' => 'Опубликован', 'archived' => 'Архив'])
                    ->required()->default('draft')->native(false),
                Select::make('category_ids')->label('Категории')->multiple()->required()
                    ->searchable()->preload()->options(fn (): array => ShopCategory::query()
                    ->orderBy('name')->pluck('name', 'id')->all())
                    ->live()->afterStateUpdated(function ($state, Get $get, Set $set): void {
                        $typeId = $get('product_type_id');
                        if (! $typeId && is_array($state) && $state !== []) {
                            $typeId = ShopCategory::query()->whereIn('id', $state)
                                ->whereNotNull('default_product_type_id')->value('default_product_type_id');
                            if ($typeId) {
                                $set('product_type_id', $typeId);
                            }
                        }
                        $set('characteristics', app(ShopCatalogService::class)->characteristicRows(
                            (array) $state, (array) $get('characteristics'),
                            $typeId ? (int) $typeId : null,
                        ));
                    })->columnSpanFull(),
                Select::make('product_type_id')->label('Тип товара / набор характеристик')
                    ->searchable()->preload()->required()->native(false)
                    ->options(fn (): array => ShopProductType::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->helperText('Тип определяет характеристики независимо от категорий. Выберите один тип.')
                    ->live()->afterStateUpdated(function ($state, Get $get, Set $set): void {
                        $set('characteristics', app(ShopCatalogService::class)->characteristicRows(
                            (array) $get('category_ids'), (array) $get('characteristics'),
                            $state ? (int) $state : null,
                        ));
                    })->columnSpanFull(),
                Select::make('tag_ids')->label('Теги')->multiple()->searchable()->preload()
                    ->options(fn (): array => ShopTag::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->helperText('Новые теги добавляются в разделе «Магазин → Теги».')->columnSpanFull(),
                Textarea::make('description')->label('Описание')->rows(5)->columnSpanFull(),
            ]),
            Section::make('Цена и склад')->columns(2)->schema([
                TextInput::make('price_rub')->label('Текущая цена, ₽')
                    ->numeric()->minValue(0)->maxValue(999999999)->helperText('В форме рубли, в БД копейки.'),
                TextInput::make('old_price_rub')->label('Старая цена, ₽')
                    ->numeric()->minValue(0)->maxValue(999999999)
                    ->helperText('Необязательно. Для отображения зачёркнутой цены.'),
                Select::make('stock_status')->label('Наличие')
                    ->options([
                        'in_stock' => 'На складе',
                        'out_of_stock' => 'Нет в наличии',
                        'preorder' => 'Предзаказ',
                        'on_request' => 'Наличие уточняется',
                    ])->required()->default('out_of_stock')->native(false),
                TextInput::make('stock_quantity')->label('Остаток, шт.')
                    ->integer()->numeric()->minValue(0)->default(0)->required(),
                TextInput::make('sort_order')->label('Порядок')->integer()->numeric()
                    ->minValue(0)->default(100)->required(),
            ]),
            Section::make('Характеристики')->description(
                'Поля определяются выбранным типом товара. Категории и родительские категории '
                .'не влияют на набор характеристик.'
            )->schema([
                Repeater::make('characteristics')->label('Значения')->default([])
                    ->schema([
                        Hidden::make('attribute_id'),
                        TextInput::make('attribute_name')->label('Характеристика')
                            ->formatStateUsing(fn (Get $get): string => self::attributeLabel((int) $get('attribute_id')))
                            ->disabled()->dehydrated(false),
                        TextInput::make('value')->label('Значение')->maxLength(5000)
                            ->helperText(fn (Get $get): string => self::attributeHelp((int) $get('attribute_id'))),
                    ])->columns(2)->addable(false)->deletable(false)->reorderable(false)
                    ->columnSpanFull(),
            ]),
            Section::make('Фотографии')->schema([
                FileUpload::make('images')->label('Галерея товара')->image()->multiple()
                    ->reorderable()->imageEditor()->disk('public')->directory('shop/products')
                    ->visibility('public')->columnSpanFull(),
            ]),
        ]);
    }

    public static function attributeLabel(int $attributeId): string
    {
        $attribute = ShopAttribute::query()->find($attributeId);

        return $attribute?->name.($attribute?->unit ? ' ('.$attribute->unit.')' : '') ?: 'Характеристика';
    }

    public static function attributeHelp(int $attributeId): string
    {
        $attribute = ShopAttribute::query()->find($attributeId);
        if (! $attribute) {
            return '';
        }

        return match ($attribute->type) {
            'number' => 'Числовое значение.',
            'boolean' => 'Введите 1 (да) или 0 (нет).',
            'select' => 'Допустимые значения: '.implode(', ', (array) $attribute->options),
            default => '',
        };
    }

    public static function kopecks(mixed $rubles): ?int
    {
        if ($rubles === null || $rubles === '') {
            return null;
        }
        $value = str_replace(',', '.', trim((string) $rubles));
        if (! preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/D', $value)) {
            throw ValidationException::withMessages(['data.price_rub' => 'Цена должна быть указана в рублях с точностью до копейки.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort_order')->columns([
            ImageColumn::make('images.0')->label('Фото')->disk('public')->square(),
            TextColumn::make('name')->label('Товар')->searchable()->sortable(),
            TextColumn::make('sku')->label('Артикул')->searchable(),
            TextColumn::make('categories.name')->label('Категории')->listWithLineBreaks()->limitList(3),
            TextColumn::make('productType.name')->label('Тип товара'),
            TextColumn::make('price_kopecks')->label('Цена')
                ->formatStateUsing(fn ($state): string => ShopProduct::rubles($state === null ? null : (int) $state) ?? '—'),
            TextColumn::make('stock_quantity')->label('Остаток')->numeric()->sortable(),
            TextColumn::make('stock_status')->label('Склад')->badge()
                ->formatStateUsing(fn (string $state): string => [
                    'in_stock' => 'На складе', 'out_of_stock' => 'Нет', 'preorder' => 'Предзаказ',
                    'on_request' => 'Уточняется',
                ][$state] ?? $state),
            TextColumn::make('status')->label('Статус')->badge()
                ->formatStateUsing(fn (string $state): string => [
                    'draft' => 'Черновик', 'published' => 'Опубликован', 'archived' => 'Архив',
                ][$state] ?? $state),
        ])->filters([
            SelectFilter::make('status')->options([
                'draft' => 'Черновик', 'published' => 'Опубликован', 'archived' => 'Архив',
            ]),
            SelectFilter::make('stock_status')->options([
                'in_stock' => 'На складе', 'out_of_stock' => 'Нет в наличии', 'preorder' => 'Предзаказ',
                'on_request' => 'Наличие уточняется',
            ]),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListShopProducts::route('/'),
            'create' => CreateShopProduct::route('/create'),
            'edit' => EditShopProduct::route('/{record}/edit')];
    }
}
