<?php

namespace App\Filament\Backoffice\Resources\BackofficeUsers\Pages;

use App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Application\Services\GlobalOperationalPermissionManager;
use App\Modules\Identity\Application\Services\StaffSpecializationManager;
use App\Modules\Identity\Domain\Models\ManagerSpecialization;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use App\Modules\Identity\Application\UseCases\AdminUpdateUserSystemRoleHandler;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EditBackofficeUser extends EditRecord
{
    protected static string $resource = BackofficeUserResource::class;

    /** Role changes intentionally have their own save operation. Changing role
     * defaults must not accidentally persist stale permission checkboxes. */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('changeSystemRole')
                ->label('Изменить системную роль')
                ->icon('heroicon-o-user-circle')
                ->visible(fn (): bool => auth('web')->user()?->can(
                    'manage-user-system-role', $this->getRecord(),
                ) ?? false)
                ->modalHeading('Назначение системной роли')
                ->modalDescription('Можно назначить только роль ниже собственной. Роль «Пользователь» снимает административную роль. Глобальные права сохраняются отдельно.')
                ->modalSubmitActionLabel('Сохранить роль')
                ->fillForm(fn (): array => [
                    'new_system_role' => $this->getRecord()->system_role->value,
                ])
                ->schema([
                    Select::make('new_system_role')
                        ->label('Новая системная роль')
                        ->options(function (): array {
                            $actorRole = auth('web')->user()?->canonical()->system_role;
                            if ($actorRole === null) {
                                return [];
                            }

                            $roles = [];
                            foreach (UserSystemRoleEnum::cases() as $role) {
                                // A service/system account must never be assigned
                                // accidentally through the human user editor.
                                if ($role !== UserSystemRoleEnum::SUPERADMIN
                                    && $role !== UserSystemRoleEnum::SYSTEM
                                    && $role !== UserSystemRoleEnum::MODERATOR
                                    && $role !== UserSystemRoleEnum::EDITOR
                                    && $role->numericValue() < $actorRole->numericValue()) {
                                    $roles[$role->value] = $role->label();
                                }
                            }

                            return $roles;
                        })
                        ->native(false)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $actor = auth('web')->user()?->canonical();
                    $target = $this->getRecord();
                    abort_unless($actor !== null && $actor->can('manage-user-system-role', $target), 403);

                    $role = UserSystemRoleEnum::tryFrom((string) ($data['new_system_role'] ?? ''));
                    if ($role === null
                        || in_array($role, [
                            UserSystemRoleEnum::SUPERADMIN,
                            UserSystemRoleEnum::SYSTEM,
                            UserSystemRoleEnum::MODERATOR,
                            UserSystemRoleEnum::EDITOR,
                        ], true)
                        || $role->numericValue() >= $actor->system_role->numericValue()) {
                        throw ValidationException::withMessages([
                            'new_system_role' => 'Недопустимая системная роль.',
                        ]);
                    }

                    // Reuse the existing production use case to preserve role
                    // hierarchy, history grants, revocations and audit events.
                    app(AdminUpdateUserSystemRoleHandler::class)
                        ->handle($actor, $target->id, $role);

                    // Reload effective defaults after changing the user's role.
                    $this->redirect(BackofficeUserResource::getUrl('edit', ['record' => $target]));
                }),
            Action::make('assignSpecializations')
                ->label('Назначить специализации')
                ->icon('heroicon-o-identification')
                ->visible(fn (): bool => auth('web')->user() !== null
                    && app(StaffSpecializationManager::class)->mayManage(auth('web')->user(), $this->getRecord()))
                ->modalHeading('Специализации менеджера')
                ->modalDescription('Можно выбрать несколько профилей. Каждый добавляет фиксированные права, управляемые суперадминистратором.')
                ->fillForm(fn (): array => [
                    'staff_title' => $this->getRecord()->staff_title,
                    'specialization_ids' => $this->getRecord()->managerSpecializations()
                        ->pluck('manager_specializations.id')->map(fn ($id): string => (string) $id)->all(),
                ])
                ->schema([
                    TextInput::make('staff_title')->label('Отображаемая должность (не влияет на права)')->maxLength(120),
                    CheckboxList::make('specialization_ids')
                        ->label('Функциональные специализации')
                        ->options(fn (): array => ManagerSpecialization::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->columns(2),
                ])
                ->action(function (array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    $ids = $data['specialization_ids'] ?? [];
                    if (! is_array($ids)) {
                        throw ValidationException::withMessages(['specialization_ids' => 'Неверный список специализаций.']);
                    }
                    app(StaffSpecializationManager::class)->sync($actor, $this->getRecord(), $ids, (string) ($data['staff_title'] ?? ''));
                    $this->redirect(BackofficeUserResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $global = app(GlobalOperationalPermissionManager::class);
        $selected = $global->granted($this->getRecord());
        $data['creation_permissions'] = array_values(array_intersect(
            $selected,
            array_map(static fn (Permission $p): string => $p->value, $global->creationPermissions()),
        ));

        if (auth('web')->user()?->canonical()->system_role?->atLeast(UserSystemRoleEnum::ADMIN)) {
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
        if ($actor->system_role->atLeast(UserSystemRoleEnum::ADMIN)) {
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
            if ($actor->system_role->atLeast(UserSystemRoleEnum::ADMIN)) {
                app(BackofficePermissionManager::class)->sync($actor, $record, array_values($backoffice));
            }
        });

        return $record->refresh();
    }
}
