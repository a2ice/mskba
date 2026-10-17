<?php

namespace App\Filament\Backoffice\Resources\PageBuilder\Concerns;

use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Application\Services\PageBuilderScriptVersionStore;
use App\Modules\Content\Domain\Models\PageBuilderScriptAsset;
use Filament\Actions\Action;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Compact, consistent code editors for all three Page Builder resources.
 * Code is not part of the general record form, so saving page HTML cannot
 * inadvertently overwrite JS/CSS, and modal code actions cannot publish
 * pending slide, SEO, template or route changes.
 */
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

    /** @return array<Action> */
    protected function codeEditorActions(): array
    {
        return [
            Action::make('editBuilderCss')
                ->label('CSS')
                ->icon('heroicon-o-paint-brush')
                ->outlined()
                ->visible(fn (): bool => static::getResource()::canViewCss())
                ->modalHeading('CSS · стили')
                ->modalDescription(fn (): string => $this->getRecord()->published_at === null
                    ? 'Страница ещё не опубликована. CSS сохранится как черновик; впервые он станет публичным вместе со страницей.'
                    : 'Редактируй CSS независимо от HTML и настроек. Публикация обновит только стили; при изменении предыдущая версия останется для отката.')
                ->form([
                    CodeEditor::make('source')->label('Исходный CSS')
                        ->language(Language::Css)
                        ->disabled(fn (): bool => ! static::getResource()::canEditCss())
                        ->columnSpanFull(),
                ])
                ->fillForm(fn (): array => [
                    'source' => (string) ($this->getRecord()->fresh()?->draft_css ?? ''),
                ])
                ->modalSubmitAction(fn (Action $action) => static::getResource()::canEditCss()
                    ? $action->label(static::getResource()::canPublishCss() && $this->getRecord()->published_at !== null
                        ? 'Сохранить и опубликовать' : 'Сохранить черновик')->color('success')
                    : false)
                ->modalCancelActionLabel('Отмена')
                ->extraModalFooterActions(fn (Action $action): array => [
                    $action->makeModalSubmitAction('rollbackCssFromModal', arguments: ['operation' => 'rollback'])
                        ->label('Откатить CSS')->color('warning')
                        ->visible(fn (): bool => static::getResource()::canPublishCss()
                            && (bool) $this->getRecord()->fresh()?->has_previous_css),
                ])
                ->action(function (array $data, array $arguments): void {
                    if (($arguments['operation'] ?? null) === 'rollback') {
                        abort_unless(static::getResource()::canPublishCss(), 403);
                        app(PageBuilderPublisher::class)->rollbackCss($this->getRecord());
                        $this->getRecord()->refresh();
                        Notification::make()->title('Предыдущая версия CSS восстановлена')->success()->send();

                        return;
                    }
                    abort_unless(static::getResource()::canEditCss(), 403);
                    $publisher = app(PageBuilderPublisher::class);
                    if (! static::getResource()::canPublishCss()) {
                        $publisher->saveCssDraft($this->getRecord(), $data['source'] ?? '');
                        Notification::make()->title('Черновик CSS сохранён')->success()->send();

                        return;
                    }
                    $published = $publisher->saveAndPublishCss($this->getRecord(), $data['source'] ?? '');
                    Notification::make()->title($published
                        ? 'CSS сохранён и опубликован' : 'Черновик CSS сохранён; сначала опубликуй страницу')->success()->send();
                }),

            Action::make('editBuilderJs')
                ->label('JS')
                ->icon('heroicon-o-code-bracket')
                ->outlined()
                ->visible(fn (): bool => static::getResource()::canViewJs())
                ->modalHeading('JavaScript · редактор')
                ->modalDescription(function (): string {
                    $script = $this->scriptAsset();
                    if ($script?->is_enabled) {
                        return 'JS включён для посетителей. Сохранение и публикация обновят исполняемый код. Можно отключить или откатить.';
                    }

                    return 'JS сейчас отключён. «Сохранить и опубликовать» сразу включит его для новых загрузок страницы. Публикуй только проверенный код.';
                })
                ->form([
                    CodeEditor::make('source')->label('Исходный JavaScript')
                        ->language(Language::JavaScript)
                        ->disabled(fn (): bool => ! static::getResource()::canEditJs())
                        ->columnSpanFull(),
                ])
                ->fillForm(fn (): array => [
                    'source' => (string) ($this->scriptAsset()?->draft_js ?? ''),
                ])
                ->modalSubmitAction(fn (Action $action) => static::getResource()::canEditJs()
                    ? $action->label(static::getResource()::canPublishJs()
                        ? 'Сохранить и опубликовать' : 'Сохранить черновик')->color('success')
                    : false)
                ->modalCancelActionLabel('Отмена')
                ->extraModalFooterActions(fn (Action $action): array => [
                    $action->makeModalSubmitAction('disableJsFromModal', arguments: ['operation' => 'disable'])
                        ->label('Отключить JS')->color('danger')
                        ->visible(fn (): bool => static::getResource()::canPublishJs()
                            && (bool) $this->scriptAsset()?->is_enabled),
                    $action->makeModalSubmitAction('rollbackJsFromModal', arguments: ['operation' => 'rollback'])
                        ->label('Откатить JS')->color('warning')
                        ->visible(fn (): bool => static::getResource()::canPublishJs()
                            && $this->scriptAsset()?->previous_hash !== null),
                ])
                ->action(function (array $data, array $arguments): void {
                    $operation = $arguments['operation'] ?? 'save_publish';
                    $service = app(PageBuilderScriptVersionStore::class);
                    $record = $this->getRecord();
                    $actor = auth('web')->user();
                    if ($operation === 'disable') {
                        abort_unless(static::getResource()::canPublishJs(), 403);
                        $service->setEnabled($actor, $record, false);
                        Notification::make()->title('JS отключён для новых загрузок страницы')->success()->send();

                        return;
                    }
                    if ($operation === 'rollback') {
                        abort_unless(static::getResource()::canPublishJs(), 403);
                        $service->rollbackAndEnable($actor, $record);
                        Notification::make()->title('Предыдущая версия JS восстановлена и включена')->success()->send();

                        return;
                    }
                    abort_unless(static::getResource()::canEditJs(), 403);
                    if (! static::getResource()::canPublishJs()) {
                        $service->saveDraft($actor, $record, $data['source'] ?? '');
                        Notification::make()->title('Черновик JS сохранён')->success()->send();

                        return;
                    }
                    $service->savePublishAndEnable($actor, $record, $data['source'] ?? '');
                    Notification::make()->title('JavaScript сохранён, опубликован и включён')->success()->send();
                }),
        ];
    }
}
