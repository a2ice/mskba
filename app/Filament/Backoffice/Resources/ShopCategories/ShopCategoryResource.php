<?php

namespace App\Filament\Backoffice\Resources\ShopCategories;

use App\Filament\Backoffice\Resources\ShopCategories\Pages\CreateShopCategory;
use App\Filament\Backoffice\Resources\ShopCategories\Pages\EditShopCategory;
use App\Filament\Backoffice\Resources\ShopCategories\Pages\ListShopCategories;
use App\Modules\Shop\Domain\Models\ShopAttribute;
use App\Modules\Shop\Domain\Models\ShopCategory;
use App\Modules\Shop\Domain\Models\ShopTag;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class ShopCategoryResource extends Resource
{
    protected static ?string $model = ShopCategory::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Магазин';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return 'Категории';
    }

    public static function getModelLabel(): string
    {
        return 'категория';
    }

    public static function getPluralModelLabel(): string
    {
        return 'категории';
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
        return self::canViewAny() && $record instanceof ShopCategory
            && ! $record->children()->exists() && ! $record->products()->exists();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Категория')->columns(2)->schema([
                TextInput::make('name')->label('Название')->required()->maxLength(180)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, Set $set, ?ShopCategory $record) => $record === null
                        ? $set('slug', Str::slug((string) $state))
                        : null),
                TextInput::make('slug')->label('Адрес (slug)')->required()->maxLength(190)
                    ->regex('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D')->unique(ignoreRecord: true),
                Select::make('parent_id')->label('Родительская категория')
                    ->options(fn (): array => ShopCategory::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->preload()->native(false)->placeholder('Корневая категория'),
                Select::make('status')->label('Статус')
                    ->options(['draft' => 'Черновик', 'published' => 'Опубликована', 'hidden' => 'Скрыта'])
                    ->required()->default('draft')->native(false),
                TextInput::make('sort_order')->label('Порядок')->numeric()->integer()
                    ->minValue(0)->required()->default(100),
                Textarea::make('description')->label('Описание')->rows(4)->columnSpanFull(),
            ]),
            Section::make('Теги и характеристики')->columns(2)->schema([
                Select::make('tag_ids')->label('Теги')->multiple()->searchable()->preload()
                    ->options(fn (): array => ShopTag::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->helperText('Новые теги добавляются в разделе «Магазин → Теги».'),
                Select::make('attribute_ids')->label('Характеристики категории')->multiple()
                    ->searchable()->preload()
                    ->options(fn (): array => ShopAttribute::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->helperText('Товары унаследуют эти характеристики, включая характеристики родительских категорий.'),
            ]),
            Section::make('Изображения')->schema([
                FileUpload::make('images')->label('Галерея категории')->image()->multiple()
                    ->reorderable()->imageEditor()->disk('public')->directory('shop/categories')
                    ->visibility('public')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort_order')->columns([
            ImageColumn::make('images.0')->label('Фото')->disk('public')->square(),
            TextColumn::make('name')->label('Категория')->searchable()->sortable(),
            TextColumn::make('parent.name')->label('Родитель'),
            TextColumn::make('slug')->label('Slug')->searchable(),
            TextColumn::make('status')->label('Статус')->badge()
                ->formatStateUsing(fn (string $state): string => [
                    'draft' => 'Черновик', 'published' => 'Опубликована', 'hidden' => 'Скрыта',
                ][$state] ?? $state),
            TextColumn::make('products_count')->counts('products')->label('Товаров'),
        ])->filters([
            SelectFilter::make('status')->label('Статус')
                ->options(['draft' => 'Черновик', 'published' => 'Опубликована', 'hidden' => 'Скрыта']),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShopCategories::route('/'),
            'create' => CreateShopCategory::route('/create'),
            'edit' => EditShopCategory::route('/{record}/edit'),
        ];
    }
}
