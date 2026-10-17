<?php

namespace App\Filament\Backoffice\Resources\PageBuilder\Concerns;

use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Application\Services\PageBuilderScriptVersionStore;
use App\Modules\Content\Domain\Models\PageBuilderScriptAsset;
use Filament\Actions\Action;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;

trait BuilderScriptEditorActions
{
    private function scriptAsset(): ?PageBuilderScriptAsset
    {
        $record = $this->getRecord();

        return PageBuilderScriptAsset::query()
            ->where('entity_type', PageBuilderPublisher::type($record))
            ->where('entity_id', $record->getKey())
            ->first();
    }

    protected function scriptActions(): array
    {
        return [
            Action::make('viewBuilderJs')->label('Просмотреть JS')
                ->icon('heroicon-o-eye')
                ->visible(fn (): bool => static::getResource()::canViewJs())
                ->modalHeading('JavaScript · текущий черновик')
                ->modalSubmitAction(false)
                ->modalContent(fn (): View => view('page-builder.js-source', [
                    'source' => (string) ($this->scriptAsset()?->draft_js ?? ''),
                ])),

            Action::make('editBuilderJs')->label('Редактировать JS')
                ->icon('heroicon-o-code-bracket')
                ->visible(fn (): bool => static::getResource()::canEditJs())
                ->modalHeading('JavaScript · черновик')
                ->modalDescription('Сохранение не меняет публичный сайт. Для запуска потребуются отдельные публикация и включение.')
                ->form([
                    CodeEditor::make('source')->label('Исходный JavaScript')
                        ->language(Language::JavaScript)
                        ->columnSpanFull(),
                ])
                ->fillForm(fn (): array => [
                    'source' => (string) ($this->scriptAsset()?->draft_js ?? ''),
                ])
                ->action(function (array $data): void {
                    abort_unless(static::getResource()::canEditJs(), 403);
                    app(PageBuilderScriptVersionStore::class)->saveDraft(
                        auth('web')->user(), $this->getRecord(), $data['source'] ?? '',
                    );
                    Notification::make()->title('Черновик JavaScript сохранён')->success()->send();
                }),

            Action::make('previewBuilderJs')->label('Тест JS · песочница')
                ->icon('heroicon-o-shield-check')
                ->visible(fn (): bool => static::getResource()::canViewJs())
                ->modalHeading('Изолированный предпросмотр JavaScript')
                ->modalDescription('Тестовый DOM, не копия страницы. Песочница не имеет доступа к аккаунту, сайту или сети. Изменения в редакторе сначала сохрани.')
                ->modalSubmitAction(false)
                ->modalContent(fn (): View => view('page-builder.js-sandbox', [
                    'src' => route('page-builder.js-preview', [
                        'type' => PageBuilderPublisher::type($this->getRecord()),
                        'id' => $this->getRecord()->getKey(),
                    ]),
                ])),

            Action::make('publishBuilderJs')->label('Опубликовать JS')
                ->icon('heroicon-o-arrow-up-tray')->color('warning')
                ->requiresConfirmation()
                ->modalDescription('Создаст текущую JS-версию из сохранённого черновика. Уже включённый скрипт обновится для новых загрузок страницы; если он выключен, публикация не запустит его.')
                ->visible(fn (): bool => static::getResource()::canPublishJs()
                    && $this->scriptAsset()?->draft_js !== null)
                ->action(function (): void {
                    abort_unless(static::getResource()::canPublishJs(), 403);
                    app(PageBuilderScriptVersionStore::class)->publish(auth('web')->user(), $this->getRecord());
                    Notification::make()->title('JavaScript опубликован')->success()->send();
                }),

            Action::make('enableBuilderJs')->label('Включить JS')
                ->icon('heroicon-o-play')->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Опубликованный JS будет выполняться с правами сайта на публичных страницах. Включай только проверенный код. В уже открытых вкладках выключение не прерывает работающий JS.')
                ->visible(fn (): bool => static::getResource()::canPublishJs()
                    && $this->scriptAsset()?->published_hash !== null
                    && ! (bool) $this->scriptAsset()?->is_enabled)
                ->action(function (): void {
                    abort_unless(static::getResource()::canPublishJs(), 403);
                    app(PageBuilderScriptVersionStore::class)->setEnabled(auth('web')->user(), $this->getRecord(), true);
                    Notification::make()->title('JavaScript включён для новых загрузок страницы')->success()->send();
                }),

            Action::make('disableBuilderJs')->label('Отключить JS')
                ->icon('heroicon-o-stop-circle')->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Новые загрузки страниц больше не подключат JS. Уже выполняющийся код в открытых вкладках останется до обновления страницы.')
                ->visible(fn (): bool => static::getResource()::canPublishJs()
                    && (bool) $this->scriptAsset()?->is_enabled)
                ->action(function (): void {
                    abort_unless(static::getResource()::canPublishJs(), 403);
                    app(PageBuilderScriptVersionStore::class)->setEnabled(auth('web')->user(), $this->getRecord(), false);
                    Notification::make()->title('JavaScript отключён для новых загрузок')->success()->send();
                }),

            Action::make('rollbackBuilderJs')->label('Откатить JS')
                ->icon('heroicon-o-arrow-uturn-left')->color('warning')
                ->requiresConfirmation()
                ->modalDescription('Вернёт предыдущую опубликованную JS-версию. Черновик останется прежним. Скрипт остаётся включённым или выключенным.')
                ->visible(fn (): bool => static::getResource()::canPublishJs()
                    && $this->scriptAsset()?->previous_hash !== null)
                ->action(function (): void {
                    abort_unless(static::getResource()::canPublishJs(), 403);
                    app(PageBuilderScriptVersionStore::class)->rollback(auth('web')->user(), $this->getRecord());
                    Notification::make()->title('Предыдущая версия JavaScript восстановлена')->success()->send();
                }),
        ];
    }
}
