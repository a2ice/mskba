<?php

namespace App\Filament\Backoffice\Resources\Venues\RelationManagers;

use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Venue\Application\Services\VenueGalleryManager;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

final class PhotosRelationManager extends RelationManager
{
    protected static string $relationship = 'media';
    protected static ?string $title = 'Фотографии площадки';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return VenueResource::authorized() && ! $ownerRecord->trashed();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query): Builder => $query->where('collection', 'gallery'))
            ->columns([
                ImageColumn::make('path')->label('Фото')
                    ->getStateUsing(fn (Media $record): string => url($record->publicUrl()))
                    ->square()->height(76),
                IconColumn::make('is_featured')->label('Главное')->boolean(),
                TextColumn::make('mime')->label('Формат'),
                TextColumn::make('created_at')->label('Добавлено')->dateTime('d.m.Y H:i'),
            ])
            ->headerActions([
                Action::make('upload')->label('Загрузить фото')->icon('heroicon-o-arrow-up-tray')
                    ->visible(fn (): bool => VenueResource::authorized())
                    ->schema([
                        FileUpload::make('photo')->label('Фото')->required()->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(10240)->storeFiles(false)
                            ->helperText('До 10 МБ. Галерея ограничена тремя фотографиями; новое станет главным.'),
                    ])
                    ->action(function (array $data): void {
                        abort_unless(VenueResource::authorized(), 403);
                        $file = $data['photo'] ?? null;
                        abort_unless($file instanceof UploadedFile, 422, 'Не удалось получить файл.');
                        $contents = file_get_contents($file->getRealPath());
                        abort_unless(is_string($contents), 422, 'Не удалось прочитать файл.');
                        app(VenueGalleryManager::class)->store($this->getOwnerRecord(), $contents, forcePublished: true);
                        Notification::make()->success()->title('Фотография добавлена')->send();
                    }),
            ])
            ->recordActions([
                Action::make('featured')->label('Сделать главным')->icon('heroicon-o-star')
                    ->visible(fn (Media $record): bool => ! $record->is_featured)
                    ->action(function (Media $record): void {
                        abort_unless(VenueResource::authorized(), 403);
                        app(VenueGalleryManager::class)->activate(
                            $this->getOwnerRecord(), $record->id, forcePublished: true);
                    }),
                Action::make('remove')->label('Удалить фото')->icon('heroicon-o-trash')
                    ->color('danger')->requiresConfirmation()
                    ->action(function (Media $record): void {
                        abort_unless(VenueResource::authorized(), 403);
                        app(VenueGalleryManager::class)->delete(
                            $this->getOwnerRecord(), $record->id, forcePublished: true);
                    }),
            ]);
    }
}
