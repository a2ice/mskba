<?php

namespace App\Filament\Backoffice\Resources\PageBuilder\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * Filament disables or hides unprivileged CSS fields, but that alone does not
 * protect direct Livewire invocations. Guard form-data mutation server-side.
 */
trait BuilderCodeFormGuard
{
    protected function guardDraftCss(array $data, ?string $existing): array
    {
        if (static::getResource()::canEditCss()) {
            return $data;
        }

        if (array_key_exists('draft_css', $data)
            && trim((string) $data['draft_css']) !== trim((string) $existing)) {
            abort(403, 'Нет прав на редактирование CSS конструктора.');
        }

        unset($data['draft_css']);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->guardDraftCss($data, (string) ($this->record?->draft_css ?? ''));
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->guardDraftCss($data, '');
    }

    protected function assertCanPublishCode(): void
    {
        $record = $this->getRecord();
        if (trim((string) $record->draft_css) !== trim((string) $record->published_css)
            && ! static::getResource()::canPublishCss()) {
            abort(403, 'Нет прав на публикацию CSS конструктора.');
        }
    }
}
