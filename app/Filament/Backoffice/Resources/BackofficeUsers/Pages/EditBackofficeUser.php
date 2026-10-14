<?php

namespace App\Filament\Backoffice\Resources\BackofficeUsers\Pages;

use App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class EditBackofficeUser extends EditRecord
{
    protected static string $resource = BackofficeUserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['backoffice_permissions'] = app(BackofficePermissionManager::class)
            ->granted($this->getRecord());

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $allowed = array_map(fn (Permission $permission): string => $permission->value,
            Permission::backofficeCases());
        $selected = $data['backoffice_permissions'] ?? [];

        if (! is_array($selected) || array_filter($selected, 'is_string') !== $selected
            || array_diff($selected, $allowed) !== []) {
            throw ValidationException::withMessages([
                'data.backoffice_permissions' => 'Некорректный набор административных разрешений.',
            ]);
        }

        app(BackofficePermissionManager::class)->sync(
            auth('web')->user(), $record, array_values($selected),
        );

        // Do not update User fields at all: the form exclusively writes the
        // operational_permissions table and leaves roles/statuses/password intact.
        return $record->refresh();
    }
}
