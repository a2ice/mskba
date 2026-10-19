<?php

namespace App\Filament\Backoffice\Resources\SiteMedia\Pages;

use App\Filament\Backoffice\Resources\SiteMedia\SiteMediaAssetResource;
use App\Filament\Backoffice\Resources\SiteMedia\SiteMediaFolderResource;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Media\Application\Services\SiteMediaManager;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Http\UploadedFile;

final class ListSiteMediaAssets extends ListRecords
{
    protected static string $resource = SiteMediaAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upload')->label('Загрузить изображение')->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => SiteMediaAssetResource::allowed(Permission::BACKOFFICE_SITE_MEDIA_UPLOAD))
                ->schema([
                    FileUpload::make('file')->label('Изображение')->required()->image()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(10240)->storeFiles(false),
                    TextInput::make('title')->label('Название')->maxLength(180),
                    Select::make('folder_id')->label('Папка')
                        ->options(fn (): array => SiteMediaAssetResource::folderOptions())->searchable()->native(false),
                    Toggle::make('optimize')->label('Оптимизировать в WebP')
                        ->helperText('По умолчанию сохраняется оригинал — важно для PNG и спрайтов.')
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    abort_unless(SiteMediaAssetResource::allowed(Permission::BACKOFFICE_SITE_MEDIA_UPLOAD), 403);
                    $file = $data['file'] ?? null;
                    abort_unless($file instanceof UploadedFile, 422);
                    app(SiteMediaManager::class)->upload($file, $data['folder_id'] ?: null, $data['title'] ?? null,
                        (bool) ($data['optimize'] ?? false));
                    Notification::make()->title('Изображение загружено')->success()->send();
                }),
            Action::make('folders')->label('Папки')->icon('heroicon-o-folder')
                ->url(fn (): string => SiteMediaFolderResource::getUrl('index')),
        ];
    }
}
