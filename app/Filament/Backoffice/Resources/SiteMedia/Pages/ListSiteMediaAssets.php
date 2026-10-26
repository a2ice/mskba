<?php

namespace App\Filament\Backoffice\Resources\SiteMedia\Pages;

use App\Filament\Backoffice\Resources\SiteMedia\SiteMediaAssetResource;
use App\Filament\Backoffice\Resources\SiteMedia\SiteMediaFolderResource;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Media\Application\Services\SiteMediaManager;
use App\Modules\Media\Domain\Models\SiteMediaAsset;
use App\Modules\Media\Infrastructure\Jobs\GenerateSiteMediaLqipChunk;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
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
                    FileUpload::make('files')->label('Изображения')->required()->image()->multiple()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(10240)->storeFiles(false),
                    TextInput::make('title')->label('Название (если выбран один файл)')->maxLength(180),
                    Select::make('folder_id')->label('Папка')
                        ->options(fn (): array => SiteMediaAssetResource::folderOptions())->searchable()->native(false),
                    Toggle::make('optimize')->label('Оптимизировать в WebP')
                        ->helperText('По умолчанию сохраняется оригинал — важно для PNG и спрайтов.')
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    abort_unless(SiteMediaAssetResource::allowed(Permission::BACKOFFICE_SITE_MEDIA_UPLOAD), 403);
                    $files = $data['files'] ?? [];
                    abort_unless(is_array($files) && $files !== [], 422);
                    foreach ($files as $file) {
                        abort_unless($file instanceof UploadedFile, 422);
                        app(SiteMediaManager::class)->upload($file, $data['folder_id'] ?: null,
                            count($files) === 1 ? ($data['title'] ?? null) : null,
                            (bool) ($data['optimize'] ?? false));
                    }
                    Notification::make()->title('Загружено изображений: '.count($files))->success()->send();
                }),
            Action::make('generateMissingLqip')->label('Создать недостающие LQIP')
                ->icon('heroicon-o-photo')
                ->visible(fn (): bool => SiteMediaAssetResource::allowed(Permission::BACKOFFICE_SITE_MEDIA_EDIT))
                ->requiresConfirmation()
                ->modalDescription('Обработать медиатеку в фоновой очереди без замены оригиналов и версий? Уже созданные миниатюры будут пропущены.')
                ->action(function (): void {
                    abort_unless(SiteMediaAssetResource::allowed(Permission::BACKOFFICE_SITE_MEDIA_EDIT), 403);
                    $id = (string) Str::uuid();
                    $total = SiteMediaAsset::query()->whereNotNull('current_media_id')->count();
                    $ttl = now()->addDay();
                    Cache::put('site-media:lqip:latest', $id, $ttl);
                    Cache::put('site-media:lqip:'.$id.':total', $total, $ttl);
                    foreach (['created', 'skipped', 'errors', 'processed'] as $counter) {
                        Cache::put('site-media:lqip:'.$id.':'.$counter, 0, $ttl);
                    }
                    SiteMediaAsset::query()->whereNotNull('current_media_id')->select('id')
                        ->chunkById(25, function ($assets) use ($id): void {
                            GenerateSiteMediaLqipChunk::dispatch($assets->pluck('id')->all(), $id);
                        });
                    Notification::make()->title('Генерация LQIP запущена')
                        ->body('Изображений в очереди: '.$total.'. Статус доступен рядом с кнопкой.')
                        ->success()->send();
                }),
            Action::make('lqipProgress')->label('Прогресс LQIP')->icon('heroicon-o-chart-bar')
                ->visible(fn (): bool => SiteMediaAssetResource::allowed(Permission::BACKOFFICE_SITE_MEDIA_VIEW))
                ->action(function (): void {
                    $id = Cache::get('site-media:lqip:latest');
                    if (! is_string($id)) {
                        Notification::make()->title('Массовая обработка ещё не запускалась')->info()->send();

                        return;
                    }
                    $key = 'site-media:lqip:'.$id.':';
                    $processed = (int) Cache::get($key.'processed', 0);
                    $total = (int) Cache::get($key.'total', 0);
                    Notification::make()->title($processed >= $total ? 'Обработка завершена' : 'Генерация LQIP')
                        ->body('Обработано: '.$processed.' / '.$total.
                            ' · Создано: '.(int) Cache::get($key.'created', 0).
                            ' · Уже было: '.(int) Cache::get($key.'skipped', 0).
                            ' · Ошибок: '.(int) Cache::get($key.'errors', 0))
                        ->info()->send();
                }),
            Action::make('folders')->label('Папки')->icon('heroicon-o-folder')
                ->url(fn (): string => SiteMediaFolderResource::getUrl('index')),
        ];
    }
}
