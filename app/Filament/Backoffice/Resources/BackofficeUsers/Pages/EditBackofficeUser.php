<?php

namespace App\Filament\Backoffice\Resources\BackofficeUsers\Pages;

use App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Application\Services\GlobalOperationalPermissionManager;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use Illuminate\Support\Facades\DB;
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
        $global = app(GlobalOperationalPermissionManager::class);
        $selected = $global->granted($this->getRecord());
        $data['creation_permissions'] = array_values(array_intersect(
            $selected,
            array_map(static fn (Permission $p): string => $p->value, $global->creationPermissions()),
        ));

        if (auth('web')->user()?->canonical()->system_role === UserSystemRoleEnum::SUPERADMIN) {
            $data['privileged_permissions'] = array_values(array_intersect(
                $selected,
                array_map(static fn (Permission $p): string => $p->value, $global->privilegedPermissions()),
            ));
            $data['backoffice_permissions'] = app(BackofficePermissionManager::class)
                ->granted($this->getRecord());
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $actor = auth('web')->user()?->canonical();
        $global = app(GlobalOperationalPermissionManager::class);
        abort_unless($actor && $global->mayManage($actor, $record), 403);

        $isSuper = $actor->system_role === UserSystemRoleEnum::SUPERADMIN;
        $creation = $data['creation_permissions'] ?? [];
        if (! is_array($creation)) {
            throw ValidationException::withMessages([
                'data.creation_permissions' => 'Неверный список разрешений.',
            ]);
        }

        $privileged = null;
        $backoffice = null;
        if ($isSuper) {
            $privileged = $data['privileged_permissions'] ?? [];
            $backoffice = $data['backoffice_permissions'] ?? [];
            if (! is_array($privileged) || ! is_array($backoffice)) {
                throw ValidationException::withMessages([
                    'data.privileged_permissions' => 'Неверный список разрешений.',
                ]);
            }
            $expected = array_map(static fn (Permission $p): string => $p->value, Permission::backofficeCases());
            if (array_filter($backoffice, 'is_string') !== $backoffice
                || count($backoffice) !== count(array_unique($backoffice))
                || array_diff($backoffice, $expected) !== []) {
                throw ValidationException::withMessages([
                    'data.backoffice_permissions' => 'Некорректный набор разрешений Filament.',
                ]);
            }
        }
        // Do not trust hidden fields from a forged admin Livewire request.
        // Neither user status/system role nor unrelated permissions are writable.
        DB::transaction(function () use ($actor, $record, $global, $creation, $privileged, $backoffice, $isSuper): void {
            $global->sync($actor, $record, array_values($creation), $privileged);
            if ($isSuper) {
                app(BackofficePermissionManager::class)->sync($actor, $record, array_values($backoffice));
            }
        });

        return $record->refresh();
    }
}
