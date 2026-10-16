<?php

namespace App\Filament\Backoffice\Resources\PageBuilder\Concerns;

use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Domain\Models\PageBuilderRevision;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

trait BuilderEditorActions
{
    protected function getHeaderActions(): array
    {
        $resource = static::getResource();

        return [
            Action::make('preview')->label('Предпросмотр')->icon('heroicon-o-eye')
                ->url(fn (): string => route('page-builder.preview', ['page' => $this->record->getKey()]))
                ->openUrlInNewTab()
                ->visible(fn (): bool => $this->record instanceof \App\Modules\Content\Domain\Models\PageBuilderPage
                    && $resource::canViewAny()),
            Action::make('publish')->label('Опубликовать')->icon('heroicon-o-check-circle')
                ->color('success')->requiresConfirmation()
                ->modalDescription('Публичная версия обновится только после подтверждения. Черновик сначала необходимо сохранить.')
                ->visible(fn (): bool => $resource::canPublish())
                ->action(function (): void {
                    app(PageBuilderPublisher::class)->publish($this->record);
                    Notification::make()->title('Опубликовано')->success()->send();
                    $this->refreshFormData(['published_at']);
                }),
            Action::make('restore')->label('Восстановить черновик')
                ->visible(fn (): bool => $resource::canCreate()
                    && PageBuilderRevision::query()
                        ->where('entity_type', PageBuilderPublisher::type($this->record))
                        ->where('entity_id', $this->record->getKey())->exists())
                ->form([
                    Select::make('revision_id')->label('Опубликованная версия')->required()
                        ->options(fn (): array => PageBuilderRevision::query()
                            ->where('entity_type', PageBuilderPublisher::type($this->record))
                            ->where('entity_id', $this->record->getKey())
                            ->latest('id')->limit(40)->get()
                            ->mapWithKeys(fn (PageBuilderRevision $revision): array => [
                                $revision->id => '#'.$revision->id.' · '.$revision->created_at->format('d.m.Y H:i'),
                            ])->all()),
                ])
                ->action(function (array $data): void {
                    app(PageBuilderPublisher::class)->restoreDraft($this->record, (int) $data['revision_id']);
                    Notification::make()->title('Черновик восстановлен; публичная версия не изменена')->success()->send();
                    $this->fillForm();
                }),
        ];
    }
}
