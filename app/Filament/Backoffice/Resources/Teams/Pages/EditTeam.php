<?php

namespace App\Filament\Backoffice\Resources\Teams\Pages;

use App\Filament\Backoffice\Resources\Teams\BackofficeTeamEditor;
use App\Filament\Backoffice\Resources\Teams\BackofficeTeamLogo;
use App\Filament\Backoffice\Resources\Teams\TeamResource;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class EditTeam extends EditRecord
{
    protected static string $resource = TeamResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Настройки и оформление';
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        $data['sport_types'] = $record->sportProfiles()
            ->pluck('sport_type')->map(fn ($value): string => is_string($value) ? $value : $value->value)->all();
        $data['colors'] = (array) ($record->colors ?? []);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(BackofficeTeamEditor::class)->update($record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('uploadTeamLogo')->label('Загрузить логотип')
                ->icon('heroicon-o-photo')
                ->visible(fn (): bool => TeamResource::canEdit($this->getRecord()))
                ->schema([
                    FileUpload::make('logo')->label('Логотип команды')->image()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(5120)->storeFiles(false)->required(),
                ])
                ->action(function (array $data): void {
                    abort_unless(TeamResource::canEdit($this->getRecord()), 403);
                    $file = $data['logo'] ?? null;
                    $contents = $file?->getRealPath() ? file_get_contents($file->getRealPath()) : null;
                    if (! is_string($contents) || $contents === '') {
                        throw ValidationException::withMessages(['logo' => 'Выберите корректное изображение.']);
                    }
                    app(BackofficeTeamLogo::class)->store($this->getRecord(), $contents);
                    $this->getRecord()->unsetRelation('logo');
                    Notification::make()->success()->title('Логотип сохранён')->send();
                }),
            Action::make('deleteTeamLogo')->label('Убрать логотип')
                ->icon('heroicon-o-x-circle')->color('gray')->requiresConfirmation()
                ->visible(fn (): bool => TeamResource::canEdit($this->getRecord()) && $this->getRecord()->logo !== null)
                ->action(function (): void {
                    app(BackofficeTeamLogo::class)->delete($this->getRecord());
                    $this->getRecord()->unsetRelation('logo');
                    Notification::make()->success()->title('Логотип удалён')->send();
                }),
            Action::make('archiveTeam')->label('Удалить команду')->icon('heroicon-o-trash')
                ->color('danger')->requiresConfirmation()
                ->modalDescription('Мягкое удаление доступно только командам без связанных игр. Иначе переведите команду в архив.')
                ->visible(fn (): bool => TeamResource::canDelete($this->getRecord()))
                ->action(function (): void {
                    app(BackofficeTeamEditor::class)->delete($this->getRecord());
                    $this->redirect(TeamResource::getUrl('index'));
                }),
        ];
    }
}
