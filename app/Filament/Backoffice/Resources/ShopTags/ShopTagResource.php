<?php

namespace App\Filament\Backoffice\Resources\ShopTags;

use App\Filament\Backoffice\Resources\ShopTags\Pages\CreateShopTag;
use App\Filament\Backoffice\Resources\ShopTags\Pages\EditShopTag;
use App\Filament\Backoffice\Resources\ShopTags\Pages\ListShopTags;
use App\Modules\Shop\Domain\Models\ShopTag;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
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

final class ShopTagResource extends Resource
{
    protected static ?string $model = ShopTag::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Магазин';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string
    {
        return 'Теги';
    }

    public static function getModelLabel(): string
    {
        return 'тег';
    }

    public static function getPluralModelLabel(): string
    {
        return 'теги';
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
        return self::canViewAny() && $record instanceof ShopTag
            && ! $record->categories()->exists() && ! $record->products()->exists();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Тег')->columns(2)->schema([
                TextInput::make('name')->label('Название')->required()->maxLength(100)
                    ->live(onBlur: true)->afterStateUpdated(fn ($state, Set $set, ?ShopTag $record) => $record === null ? $set('slug', Str::slug((string) $state)) : null),
                TextInput::make('slug')->label('Slug')->required()->maxLength(110)
                    ->regex('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D')->unique(ignoreRecord: true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Тег')->searchable(),
            TextColumn::make('slug')->label('Slug')->searchable(),
            TextColumn::make('categories_count')->counts('categories')->label('Категорий'),
            TextColumn::make('products_count')->counts('products')->label('Товаров'),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListShopTags::route('/'),
            'create' => CreateShopTag::route('/create'),
            'edit' => EditShopTag::route('/{record}/edit')];
    }
}
