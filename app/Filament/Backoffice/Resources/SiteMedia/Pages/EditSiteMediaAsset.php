<?php

namespace App\Filament\Backoffice\Resources\SiteMedia\Pages;

use App\Filament\Backoffice\Resources\SiteMedia\SiteMediaAssetResource;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Media\Application\Services\SiteMediaManager;
use App\Modules\Media\Application\Services\SiteMediaUsage;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Http\UploadedFile;

final class EditSiteMediaAsset extends EditRecord
{
    protected static string $resource = SiteMediaAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('replace')->label('Заменить изображение')->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => SiteMediaAssetResource::allowed(Permission::BACKOFFICE_SITE_MEDIA_EDIT))
                ->schema([
                    FileUpload::make('file')->label('Новый файл')->required()->image()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(10240)->storeFiles(false),
                    Toggle::make('optimize')->label('Оптимизировать в WebP')->default(false),
                ])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    abort_unless(SiteMediaAssetResource::allowed(Permission::BACKOFFICE_SITE_MEDIA_EDIT), 403);
                    $file = $data['file'] ?? null;
                    abort_unless($file instanceof UploadedFile, 422);
                    app(SiteMediaManager::class)->replace($this->record, $file, (bool) ($data['optimize'] ?? false));
                    Notification::make()->title('Изображение заменено. URL остался прежним.')->success()->send();
                }),
            Action::make('optimize')->label('Оптимизировать')->icon('heroicon-o-sparkles')
                ->visible(fn (): bool => SiteMediaAssetResource::allowed(Permission::BACKOFFICE_SITE_MEDIA_EDIT))
                ->requiresConfirmation()
                ->action(function (): void {
                    abort_unless(SiteMediaAssetResource::allowed(Permission::BACKOFFICE_SITE_MEDIA_EDIT), 403);
                    app(SiteMediaManager::class)->optimize($this->record);
                    Notification::make()->title('Создана оптимизированная версия')->success()->send();
                }),
            Action::make('generateLqip')->label('Создать / пересоздать LQIP')->icon('heroicon-o-photo')
                ->visible(fn (): bool => SiteMediaAssetResource::allowed(Permission::BACKOFFICE_SITE_MEDIA_EDIT))
                ->action(function (): void {
                    abort_unless(SiteMediaAssetResource::allowed(Permission::BACKOFFICE_SITE_MEDIA_EDIT), 403);
                    $created = app(SiteMediaManager::class)->generateLqip($this->record, true);
                    $notice = Notification::make()->title($created ? 'LQIP создан (до 10 КБ)' : 'LQIP для этого файла недоступен');
                    ($created ? $notice->success() : $notice->warning())->send();
                }),
            Action::make('previous')->label('Посмотреть предыдущую')->icon('heroicon-o-eye')
                ->visible(fn (): bool => SiteMediaAssetResource::allowed(Permission::BACKOFFICE_SITE_MEDIA_VIEW)
                    && $this->record->previous_media_id !== null)
                ->url(fn (): string => route('site-media.previous', ['uuid' => $this->record->uuid]))
                ->openUrlInNewTab(),
            Action::make('restore')->label('Вернуть предыдущую версию')->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (): bool => SiteMediaAssetResource::allowed(Permission::BACKOFFICE_SITE_MEDIA_RESTORE)
                    && $this->record->previous_media_id !== null)
                ->requiresConfirmation()
                ->action(function (): void {
                    abort_unless(SiteMediaAssetResource::allowed(Permission::BACKOFFICE_SITE_MEDIA_RESTORE), 403);
                    app(SiteMediaManager::class)->restore($this->record);
                    Notification::make()->title('Предыдущая версия восстановлена')->success()->send();
                }),
            Action::make('delete')->label('Удалить')->color('danger')->icon('heroicon-o-trash')
                ->visible(fn (): bool => SiteMediaAssetResource::allowed(Permission::BACKOFFICE_SITE_MEDIA_DELETE))
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'Используется в: '.(implode(', ',
                    app(SiteMediaUsage::class)->references($this->record)) ?: 'связей в Page Builder не найдено')
                    .'. Опубликованные ссылки защищены от удаления.')
                ->action(function (): void {
                    abort_unless(SiteMediaAssetResource::allowed(Permission::BACKOFFICE_SITE_MEDIA_DELETE), 403);
                    app(SiteMediaManager::class)->delete($this->record);
                    Notification::make()->title('Изображение удалено')->success()->send();
                    $this->redirect(SiteMediaAssetResource::getUrl('index'));
                }),
        ];
    }
}
