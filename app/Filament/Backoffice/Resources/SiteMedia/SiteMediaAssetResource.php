<?php

namespace App\Filament\Backoffice\Resources\SiteMedia;

use App\Filament\Backoffice\Resources\SiteMedia\Pages\EditSiteMediaAsset;
use App\Filament\Backoffice\Resources\SiteMedia\Pages\ListSiteMediaAssets;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Media\Domain\Models\SiteMediaAsset;
use App\Modules\Media\Domain\Models\SiteMediaFolder;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class SiteMediaAssetResource extends Resource
{
    protected static ?string $model = SiteMediaAsset::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Сайт';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-photo';
    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string { return 'Медиафайлы'; }
    public static function getModelLabel(): string { return 'изображение сайта'; }
    public static function getPluralModelLabel(): string { return 'изображения сайта'; }

    public static function allowed(Permission $permission): bool
    {
        $user = auth('web')->user();

        return $user && app(BackofficePermissionManager::class)->allows($user, Permission::BACKOFFICE_ACCESS)
            && app(BackofficePermissionManager::class)->allows($user, $permission);
    }

    public static function canViewAny(): bool { return self::allowed(Permission::BACKOFFICE_SITE_MEDIA_VIEW); }
    public static function canView(Model $record): bool { return $record instanceof SiteMediaAsset && self::canViewAny(); }
    public static function canCreate(): bool { return self::allowed(Permission::BACKOFFICE_SITE_MEDIA_UPLOAD); }
    public static function canEdit(Model $record): bool
    {
        return $record instanceof SiteMediaAsset && self::allowed(Permission::BACKOFFICE_SITE_MEDIA_EDIT);
    }
    public static function canDelete(Model $record): bool
    {
        return $record instanceof SiteMediaAsset && self::allowed(Permission::BACKOFFICE_SITE_MEDIA_DELETE);
    }
    public static function canDeleteAny(): bool { return false; }

    public static function folderOptions(): array
    {
        return SiteMediaFolder::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Информация об изображении')->columns(2)->schema([
                TextInput::make('title')->label('Название')->required()->maxLength(180),
                Select::make('folder_id')->label('Папка')->options(fn (): array => self::folderOptions())
                    ->searchable()->native(false)->placeholder('Без папки'),
                TextInput::make('alt')->label('Alt')->maxLength(180),
                Textarea::make('description')->label('Описание')->columnSpanFull(),
                TextInput::make('public_path')->label('Публичный URL (копируй в HTML/CSS)')
                    ->formatStateUsing(fn (?SiteMediaAsset $record): string => $record?->publicUrl() ?? '')
                    ->disabled()->dehydrated(false)->columnSpanFull(),
            ]),
        ]);
    }

    /**
     * Filament image columns require an absolute HTTP URL for routed media;
     * root-relative /media/site/UUID would be interpreted as a storage path.
     */
    public static function previewUrl(SiteMediaAsset $record): string
    {
        return url($record->publicUrl());
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            ImageColumn::make('preview')->label('Превью')
                // ImageColumn treats a relative value as a path on its storage disk.
                // Our /media/site/{uuid} route is a Laravel HTTP endpoint instead.
                ->getStateUsing(fn (SiteMediaAsset $record): string => self::previewUrl($record))
                ->square()->height(64),
            TextColumn::make('title')->label('Изображение')->searchable()->sortable(),
            TextColumn::make('folder.name')->label('Папка')->placeholder('Без папки')->sortable(),
            TextColumn::make('currentMedia.mime')->label('Формат'),
            TextColumn::make('currentMedia.size')->label('Размер, байт')->numeric(),
            TextColumn::make('resolution')->label('Размер, px')->getStateUsing(
                fn (SiteMediaAsset $record): string => $record->currentMedia?->width.' × '.$record->currentMedia?->height),
            TextColumn::make('public_url')->label('Публичный URL')
                ->getStateUsing(fn (SiteMediaAsset $record): string => $record->publicUrl())
                ->copyable()->copyMessage('URL скопирован')
                ->url(fn (SiteMediaAsset $record): string => $record->publicUrl(), shouldOpenInNewTab: true),
            TextColumn::make('updated_at')->label('Обновлено')->dateTime('d.m.Y H:i')->sortable(),
        ])->filters([
            SelectFilter::make('folder_id')->label('Папка')->options(fn (): array => self::folderOptions()),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSiteMediaAssets::route('/'),
            'edit' => EditSiteMediaAsset::route('/{record}/edit'),
        ];
    }
}
