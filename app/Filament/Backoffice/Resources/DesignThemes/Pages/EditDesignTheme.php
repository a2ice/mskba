<?php

namespace App\Filament\Backoffice\Resources\DesignThemes\Pages;

use App\Filament\Backoffice\Resources\DesignThemes\DesignThemeResource;
use App\Presentation\Theming\DesignThemeCodeManager;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

final class EditDesignTheme extends EditRecord
{
    protected static string $resource = DesignThemeResource::class;

    private function codeEditor(string $kind): Action
    {
        $css = $kind === 'css';
        $name = $css ? 'CSS' : 'JS';
        $manager = app(DesignThemeCodeManager::class);

        return Action::make($css ? 'editThemeCss' : 'editThemeJs')
            ->label($name)
            ->icon($css ? 'heroicon-o-paint-brush' : 'heroicon-o-code-bracket')
            ->outlined()
            ->visible(fn (): bool => DesignThemeResource::canView($this->record))
            ->modalHeading($name.' · код темы «'.$this->record->name.'»')
            ->extraModalWindowAttributes(['class' => 'mskba-code-modal'])
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription('Глобальный код только выбранной темы. Редактор независим от токенов, страницы и компонентов. Черновики не исполняются публично; публикация создаёт версию для отката.')
            ->form([
                CodeEditor::make('source')->label('Исходный '.$name)
                    ->language($css ? Language::Css : Language::JavaScript)
                    ->extraAttributes(['class' => 'mskba-code-editor'])
                    ->disabled(fn (): bool => ! DesignThemeResource::canEdit($this->record))
                    ->columnSpanFull(),
                Select::make('revision_id')->label('Восстановить опубликованную версию')
                    ->placeholder('Выбери версию для отката')
                    ->options(fn (): array => $manager->asset($this->record, $kind)?->revisions()
                        ->orderByDesc('id')->limit(50)->get()
                        ->mapWithKeys(fn ($revision): array => [
                            $revision->id => '#'.$revision->id.' · '
                                .$revision->created_at->format('d.m.Y H:i:s')
                                .' · '.$revision->action,
                        ])->all() ?? [])
                    ->searchable(),
            ])
            ->fillForm(fn (): array => [
                'source' => (string) ($manager->asset($this->record, $kind)?->draft_source ?? ''),
                'revision_id' => null,
            ])
            ->modalSubmitAction(fn (Action $action) => DesignThemeResource::canEdit($this->record)
                ? $action->label('Сохранить и опубликовать')->color('success') : false)
            ->modalCancelActionLabel('Отмена')
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('saveThemeDraft', arguments: ['operation' => 'draft'])
                    ->label('Сохранить черновик')->color('gray')
                    ->visible(fn (): bool => DesignThemeResource::canEdit($this->record)),
                $action->makeModalSubmitAction('restoreThemeCode', arguments: ['operation' => 'restore'])
                    ->label('Откатить версию')->color('warning')
                    ->visible(fn (): bool => DesignThemeResource::canEdit($this->record)
                        && (bool) $manager->asset($this->record, $kind)?->revisions()->exists()),
                $action->makeModalSubmitAction('disableThemeCode', arguments: ['operation' => 'disable'])
                    ->label('Отключить '.$name)->color('danger')
                    ->visible(fn (): bool => DesignThemeResource::canEdit($this->record)
                        && (bool) $manager->asset($this->record, $kind)?->is_enabled),
            ])
            ->action(function (array $data, array $arguments) use ($kind, $manager, $name): void {
                abort_unless(DesignThemeResource::canEdit($this->record), 403);
                $actor = auth('web')->user();
                $operation = $arguments['operation'] ?? 'publish';
                if ($operation === 'disable') {
                    $manager->disable($actor, $this->record, $kind);
                    Notification::make()->title($name.' отключён')->success()->send();
                    return;
                }
                if ($operation === 'restore') {
                    if (! filled($data['revision_id'] ?? null)) {
                        throw ValidationException::withMessages([
                            'revision_id' => 'Выбери версию для восстановления.',
                        ]);
                    }
                    $manager->restore($actor, $this->record, $kind, (int) $data['revision_id']);
                    Notification::make()->title($name.' восстановлен из истории')->success()->send();
                    return;
                }
                if ($operation === 'draft') {
                    $manager->saveDraft($actor, $this->record, $kind, (string) ($data['source'] ?? ''));
                    Notification::make()->title('Черновик '.$name.' сохранён')->success()->send();
                    return;
                }
                $manager->saveAndPublish($actor, $this->record, $kind, (string) ($data['source'] ?? ''));
                Notification::make()->title($name.' сохранён и опубликован')->success()->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->codeEditor('css'),
            $this->codeEditor('js'),
            Action::make('uiKit')->label('UI Kit / Гайдлайн')->icon('heroicon-o-swatch')
                ->url(fn (): string => route('design-themes.ui-kit', $this->record))
                ->openUrlInNewTab(),
            DeleteAction::make()->visible(fn () => DesignThemeResource::canDelete($this->record)),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        DesignThemeResource::validateTheme(array_replace(
            $this->record->only(['colors', 'css_code']), $data,
        ));

        return $data;
    }
}
