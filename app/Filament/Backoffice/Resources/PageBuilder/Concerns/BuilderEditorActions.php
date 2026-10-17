<?php

namespace App\Filament\Backoffice\Resources\PageBuilder\Concerns;

use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Domain\Models\PageBuilderRevision;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Content\Application\Services\PageBuilderRouteRegistry;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

trait BuilderEditorActions
{
    use BuilderCodeFormGuard;
    use BuilderScriptEditorActions;

    /**
     * Keep the default Save/Cancel actions and add a combined workflow next to
     * Save. All three Page Builder edit screens share this trait.
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
            Action::make('saveAndPublish')
                ->label('Сохранить и опубликовать')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => static::getResource()::canPublish()
                    && static::getResource()::canEdit($this->getRecord()))
                ->requiresConfirmation()
                ->modalDescription(function (): string {
                    $path = $this->getRecord() instanceof PageBuilderPage
                        ? (string) ($this->data['path'] ?? $this->getRecord()->path)
                        : null;

                    if ($path !== null && app(PageBuilderRouteRegistry::class)->inspect($path)['eligible']) {
                        return 'Текущие изменения будут сохранены и сразу опубликованы. '
                            .'Этот URL уже обслуживается Laravel: публикация включит CMS-приоритет, '
                            .'а исходный контроллер останется доступен через «Вернуть Laravel». '
                            .'Проверь, что необходимые функции страницы перенесены.';
                    }

                    return 'Текущие изменения будут сохранены и сразу опубликованы. '
                        .'В случае ошибки публикации сохранённый черновик останется доступен, '
                        .'а прежняя публичная версия не изменится.';
                })
                ->action(fn () => $this->saveAndPublish()),
            $this->getCancelFormAction(),
        ];
    }

    public function saveAndPublish(): void
    {
        $resource = static::getResource();
        // Never trust action visibility as authorization: Livewire methods may
        // also be invoked directly.
        abort_unless($resource::canEdit($this->getRecord()) && $resource::canPublish(), 403);

        try {
            // Filament validates and saves the live form, including uploads.
            // Suppress the intermediate notification and redirect.
            $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

            // Publication validates components, routes and slider settings in
            // a transaction. Failed publication preserves the previous snapshot.
            $this->assertCanPublishCode();
            app(PageBuilderPublisher::class)->publish($this->getRecord());
        } catch (ValidationException $exception) {
            // Filament's inline errors can be far above the confirmation modal
            // (inside nested/collapsed repeaters). Always surface the reason.
            $messages = collect($exception->errors())->flatten()->all();
            $missingLayer = collect(array_keys($exception->errors()))->contains(
                static fn (string $key): bool => preg_match(
                    '/(?:draft_slides|slides)\\.[^.]+\\.elements$/',
                    $key,
                ) === 1,
            );
            $message = $missingLayer
                ? 'В универсальном слайде добавь хотя бы один элемент: заголовок, текст, изображение или кнопку.'
                : (string) ($messages[0] ?? 'Проверь обязательные поля редактора.');

            Notification::make()
                ->title('Не удалось сохранить и опубликовать')
                ->body($message)
                ->danger()
                ->persistent()
                ->send();

            throw $exception;
        }

        $this->getRecord()->refresh();

        Notification::make()->title('Сохранено и опубликовано')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        $resource = static::getResource();

        return [
            ...$this->codeEditorActions(),
            Action::make('preview')->label('Предпросмотр')->icon('heroicon-o-eye')
                ->url(fn (): string => route('page-builder.preview', ['page' => $this->record->getKey()]))
                ->openUrlInNewTab()
                ->visible(fn (): bool => $this->record instanceof \App\Modules\Content\Domain\Models\PageBuilderPage
                    && $resource::canViewAny()),
            Action::make('disableCmsOverride')->label('Вернуть Laravel')
                ->icon('heroicon-o-arrow-uturn-left')->color('warning')->requiresConfirmation()
                ->modalDescription('Публичная CMS-страница останется сохранена, но URL немедленно снова будет обслуживаться исходным Laravel-контроллером.')
                ->visible(fn (): bool => $resource::canPublish()
                    && $this->record instanceof PageBuilderPage
                    && $this->record->published_route_name !== null
                    && (bool) $this->record->cms_override_enabled)
                ->action(function (): void {
                    app(PageBuilderPublisher::class)->setOverrideEnabled($this->record, false);
                    $this->record->refresh();
                    Notification::make()->title('Исходный Laravel-маршрут восстановлен')->success()->send();
                }),
            Action::make('enableCmsOverride')->label('Включить CMS-версию')
                ->icon('heroicon-o-check-circle')->color('success')->requiresConfirmation()
                ->modalDescription('На этом URL снова будет показана последняя опубликованная CMS-версия. Черновик останется неизменным.')
                ->visible(fn (): bool => $resource::canPublish()
                    && $this->record instanceof PageBuilderPage
                    && $this->record->published_at !== null
                    && $this->record->published_route_name !== null
                    && ! (bool) $this->record->cms_override_enabled)
                ->action(function (): void {
                    app(PageBuilderPublisher::class)->setOverrideEnabled($this->record, true);
                    $this->record->refresh();
                    Notification::make()->title('CMS-приоритет включён')->success()->send();
                }),
            Action::make('restore')->label('Восстановить черновик')
                ->visible(fn (): bool => $resource::canCreate()
                    && PageBuilderRevision::query()
                        ->where('entity_type', PageBuilderPublisher::type($this->record))
                        ->where('entity_id', $this->record->getKey())->where('action', 'publish')->exists())
                ->form([
                    Select::make('revision_id')->label('Опубликованная версия')->required()
                        ->options(fn (): array => PageBuilderRevision::query()
                            ->where('entity_type', PageBuilderPublisher::type($this->record))
                            ->where('entity_id', $this->record->getKey())->where('action', 'publish')
                            ->latest('id')->limit(40)->get()
                            ->mapWithKeys(fn (PageBuilderRevision $revision): array => [
                                $revision->id => '#'.$revision->id.' · '.$revision->created_at->format('d.m.Y H:i'),
                            ])->all()),
                ])
                ->action(function (array $data): void {
                    $revision = PageBuilderRevision::query()->where('entity_type', PageBuilderPublisher::type($this->record))
                        ->where('entity_id', $this->record->getKey())->where('action', 'publish')
                        ->findOrFail((int) $data['revision_id']);
                    $resource = static::getResource();
                    if (! $resource::canEditCss()
                        && trim((string) ($revision->snapshot['draft_css'] ?? '')) !== trim((string) $this->record->draft_css)) {
                        abort(403, 'Нет прав на восстановление CSS конструктора.');
                    }
                    app(PageBuilderPublisher::class)->restoreDraft($this->record, $revision->id);
                    Notification::make()->title('Черновик восстановлен; публичная версия не изменена')->success()->send();
                    $this->fillForm();
                }),
        ];
    }
}
