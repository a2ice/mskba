<?php

namespace App\Filament\Backoffice\Resources\SiteMedia;

use App\Filament\Backoffice\Resources\SiteMedia\Pages\CreateSiteMediaFolder;
use App\Filament\Backoffice\Resources\SiteMedia\Pages\EditSiteMediaFolder;
use App\Filament\Backoffice\Resources\SiteMedia\Pages\ListSiteMediaFolders;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Media\Domain\Models\SiteMediaFolder;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class SiteMediaFolderResource extends Resource
{
    protected static ?string $model = SiteMediaFolder::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Сайт';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-folder';
    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string { return 'Папки медиа'; }
    public static function getModelLabel(): string { return 'папка медиа'; }
    public static function getPluralModelLabel(): string { return 'папки медиа'; }

    public static function canViewAny(): bool { return SiteMediaAssetResource::canViewAny(); }
    public static function canView(Model $record): bool { return $record instanceof SiteMediaFolder && self::canViewAny(); }
    public static function canCreate(): bool
    {
        return SiteMediaAssetResource::allowed(Permission::BACKOFFICE_SITE_MEDIA_FOLDER_MANAGE);
    }
    public static function canEdit(Model $record): bool { return $record instanceof SiteMediaFolder && self::canCreate(); }
    public static function canDelete(Model $record): bool
    {
        return $record instanceof SiteMediaFolder && self::canCreate() && ! $record->assets()->exists();
    }
    public static function canDeleteAny(): bool { return false; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Логическая папка')->columns(2)->schema([
                TextInput::make('name')->label('Название')->required()->maxLength(150)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, $set) => $set('slug', Str::slug((string) $state))),
                TextInput::make('slug')->label('Slug')->required()->maxLength(100)
                    ->regex('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D')->unique(ignoreRecord: true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Папка')->searchable()->sortable(),
            TextColumn::make('slug')->label('Код'),
            TextColumn::make('assets_count')->counts('assets')->label('Файлов'),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSiteMediaFolders::route('/'),
            'create' => CreateSiteMediaFolder::route('/create'),
            'edit' => EditSiteMediaFolder::route('/{record}/edit'),
        ];
    }
}
