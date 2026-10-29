<?php

namespace App\Filament\Backoffice\Resources\BackofficeUsers\Pages;

use App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Application\Services\GlobalOperationalPermissionManager;
use App\Modules\Identity\Application\Services\StaffSpecializationManager;
use App\Modules\Identity\Application\Services\StaffPermissionProfiles;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Models\ManagerSpecialization;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\DatePicker;
use App\Modules\Identity\Application\UseCases\AdminUpdateUserSystemRoleHandler;
use App\Modules\Identity\Application\UseCases\AdminEditPersonalProfileHandler;
use App\Modules\Identity\Application\UseCases\AdminRevertPersonalProfileHandler;
use App\Modules\Identity\Application\UseCases\AdminEditPlayerProfileHandler;
use App\Modules\Identity\Application\UseCases\AdminRevertPlayerProfileHandler;
use App\Modules\Identity\Application\UseCases\AdminUpdateUserPermissionSnapshotHandler;
use App\Modules\Identity\Application\UseCases\AdminRevertUserPermissionSnapshotHandler;
use App\Modules\Identity\Application\UseCases\AdminEditParticipationRoleHandler;
use App\Modules\Identity\Application\UseCases\AdminRevertParticipationRoleHandler;
use App\Modules\Identity\Application\UseCases\AdminUpdateStaffSpecializationsWithAuditHandler;
use App\Modules\Identity\Application\UseCases\AdminChangeUserSystemRoleWithAuditHandler;
use App\Modules\Identity\Application\UseCases\AdminRevertUserSystemRoleHandler;
use App\Modules\Identity\Application\UseCases\AdminRevertStaffSpecializationsHandler;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\Participation\PlayerBodyTypeEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Application\UseCases\AdminChangeVenueMembershipHandler;
use App\Modules\Venue\Application\UseCases\AdminRevertVenueMembershipHandler;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
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
            Action::make('editPersonalProfile')
                ->label('Изменить личные данные')
                ->icon('heroicon-o-pencil-square')
                ->visible(fn (): bool => auth('web')->user() !== null && BackofficeUserResource::canEdit($this->getRecord()))
                ->modalHeading('Редактирование персональных данных')
                ->modalDescription('Это действие изменяет личные данные напрямую, в том числе подтверждённые. Укажи основание; прежнее состояние сохранится в журнале аудита.')
                ->modalSubmitActionLabel('Подтвердить изменение')
                ->requiresConfirmation()
                ->fillForm(function (): array {
                    $profile = $this->getRecord()->profile;
                    return [
                        'first_name' => $profile?->first_name,
                        'last_name' => $profile?->last_name,
                        'middle_name' => $profile?->middle_name,
                        'birth_date' => $profile?->birth_date?->toDateString(),
                        'gender' => $profile?->gender?->value,
                    ];
                })
                ->schema([
                    TextInput::make('first_name')->label('Имя')->maxLength(100),
                    TextInput::make('last_name')->label('Фамилия')->maxLength(100),
                    TextInput::make('middle_name')->label('Отчество')->maxLength(100),
                    DatePicker::make('birth_date')->label('Дата рождения')->maxDate(now()),
                    Select::make('gender')->label('Пол')->options([
                        UserGenderEnum::MALE->value => UserGenderEnum::MALE->label(),
                        UserGenderEnum::FEMALE->value => UserGenderEnum::FEMALE->label(),
                    ])->placeholder('Не установлен'),
                    Textarea::make('reason')->label('Основание изменения')->required()->minLength(10)->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    $values = [];
                    foreach (AdminEditPersonalProfileHandler::EDITABLE as $key) {
                        $values[$key] = ($data[$key] ?? null) === '' ? null : ($data[$key] ?? null);
                    }
                    app(AdminEditPersonalProfileHandler::class)->handle(
                        $actor, $this->getRecord(), $values, (string) ($data['reason'] ?? ''),
                    );
                    $this->redirect(BackofficeUserResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
            Action::make('revertPersonalProfile')
                ->label('Откатить изменение личных данных')
                ->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (): bool => auth('web')->user() !== null
                    && BackofficeUserResource::canEdit($this->getRecord())
                    && AuditLog::query()->where('auditable_type', User::class)
                        ->where('auditable_id', $this->getRecord()->id)
                        ->where('event', 'admin_personal_profile_changed')->exists())
                ->modalHeading('Откат изменения персональных данных')
                ->modalDescription('Откат будет отклонён, если изменённые поля успели поменяться после выбранной ревизии. Журнал изменений сохраняется.')
                ->modalSubmitActionLabel('Подтвердить откат')
                ->requiresConfirmation()
                ->schema([
                    Select::make('revision_id')->label('Ревизия')->required()
                        ->options(fn (): array => AuditLog::query()
                            ->where('auditable_type', User::class)
                            ->where('auditable_id', $this->getRecord()->id)
                            ->where('event', 'admin_personal_profile_changed')
                            ->latest('id')->limit(30)->get()
                            ->mapWithKeys(fn (AuditLog $log): array => [
                                $log->id => '#'.$log->id.' · '.$log->created_at?->format('d.m.Y H:i'),
                            ])->all()),
                    Textarea::make('reason')->label('Основание отката')->required()->minLength(10)->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    app(AdminRevertPersonalProfileHandler::class)->handle(
                        $actor, $this->getRecord(), (int) $data['revision_id'], (string) ($data['reason'] ?? ''),
                    );
                    $this->redirect(BackofficeUserResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
            Action::make('editPlayerProfile')
                ->label('Изменить профиль игрока')
                ->icon('heroicon-o-adjustments-horizontal')
                ->visible(fn (): bool => BackofficeUserResource::canEdit($this->getRecord())
                    && $this->getRecord()->hasActiveRole(UserParticipationRoleEnum::PLAYER->value))
                ->modalHeading('Параметры игрока')
                ->modalDescription('Изменение характеристик потребует основания и будет доступно для безопасного отката.')
                ->modalSubmitActionLabel('Подтвердить изменение')
                ->requiresConfirmation()
                ->fillForm(function (): array {
                    $profile = $this->getRecord()->playerProfile;
                    return [
                        'height_cm' => $profile?->height_cm,
                        'weight_kg' => $profile?->weight_kg,
                        'body_type' => $profile?->body_type?->value,
                        'experience_started_year' => $profile?->experience_started_year,
                        'comment' => $profile?->comment,
                    ];
                })
                ->schema([
                    TextInput::make('height_cm')->label('Рост, см')->numeric()->minValue(150)->maxValue(220),
                    TextInput::make('weight_kg')->label('Вес, кг')->numeric()->minValue(40)->maxValue(140),
                    Select::make('body_type')->label('Телосложение')
                        ->options(collect(PlayerBodyTypeEnum::cases())->mapWithKeys(
                            fn (PlayerBodyTypeEnum $type): array => [$type->value => $type->label()],
                        )->all()),
                    TextInput::make('experience_started_year')->label('Год начала занятий')
                        ->numeric()->minValue(now()->year - 50)->maxValue(now()->year - 10),
                    Textarea::make('comment')->label('Комментарий')->maxLength(1000),
                    Textarea::make('reason')->label('Основание изменения')
                        ->required()->minLength(10)->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    $values = [];
                    foreach (AdminEditPlayerProfileHandler::EDITABLE as $key) {
                        $values[$key] = ($data[$key] ?? null) === '' ? null : ($data[$key] ?? null);
                    }
                    app(AdminEditPlayerProfileHandler::class)->handle(
                        $actor, $this->getRecord(), $values, (string) ($data['reason'] ?? ''),
                    );
                    $this->redirect(BackofficeUserResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
            Action::make('revertPlayerProfile')
                ->label('Откатить параметры игрока')
                ->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (): bool => BackofficeUserResource::canEdit($this->getRecord())
                    && AuditLog::query()->where('auditable_type', User::class)
                        ->where('auditable_id', $this->getRecord()->id)
                        ->where('event', 'admin_player_profile_changed')->exists())
                ->modalHeading('Откат параметров игрока')
                ->modalDescription('Сверяем текущие значения с выбранной ревизией. Более поздние изменения не перезаписываются.')
                ->modalSubmitActionLabel('Подтвердить откат')
                ->requiresConfirmation()
                ->schema([
                    Select::make('revision_id')->label('Ревизия')->required()
                        ->options(fn (): array => AuditLog::query()
                            ->where('auditable_type', User::class)
                            ->where('auditable_id', $this->getRecord()->id)
                            ->where('event', 'admin_player_profile_changed')
                            ->latest('id')->limit(30)->get()
                            ->mapWithKeys(fn (AuditLog $log): array => [
                                $log->id => '#'.$log->id.' · '.$log->created_at?->format('d.m.Y H:i'),
                            ])->all()),
                    Textarea::make('reason')->label('Основание отката')
                        ->required()->minLength(10)->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    app(AdminRevertPlayerProfileHandler::class)->handle(
                        $actor, $this->getRecord(), (int) $data['revision_id'], (string) ($data['reason'] ?? ''),
                    );
                    $this->redirect(BackofficeUserResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
            Action::make('editParticipationRole')
                ->label('Изменить роль участия')
                ->icon('heroicon-o-user-group')
                ->visible(fn (): bool => BackofficeUserResource::canEdit($this->getRecord())
                    && $this->getRecord()->participationRoles(false)->exists())
                ->modalHeading('Редактирование роли участия')
                ->modalDescription('Изменение статуса может отключить соответствующий раздел профиля. Сохраняется отдельная аудируемая ревизия.')
                ->modalSubmitActionLabel('Подтвердить изменение')
                ->requiresConfirmation()
                ->fillForm(function (array $arguments): array {
                    $role = $this->getRecord()->participationRoles(false)
                        ->find((int) ($arguments['role_id'] ?? 0));
                    return $role === null ? [] : [
                        'role_id' => $role->id,
                        'status' => $role->status->value,
                        'comment' => $role->comment,
                    ];
                })
                ->schema([
                    Select::make('role_id')->label('Роль')
                        ->options(fn (): array => $this->getRecord()->participationRoles(false)->get()
                            ->mapWithKeys(fn ($role): array => [
                                $role->id => $role->role->label().' · '.$role->status->label(),
                            ])->all())->required()->native(false),
                    Select::make('status')->label('Статус')
                        ->options(collect(UserParticipationRoleStatusEnum::cases())
                            ->mapWithKeys(fn (UserParticipationRoleStatusEnum $status): array => [
                                $status->value => $status->label(),
                            ])->all())->required(),
                    Textarea::make('comment')->label('Комментарий к роли')->maxLength(2000),
                    Textarea::make('reason')->label('Основание изменения')
                        ->required()->minLength(10)->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    app(AdminEditParticipationRoleHandler::class)->handle(
                        $actor, $this->getRecord(), (int) ($data['role_id'] ?? 0),
                        (string) ($data['status'] ?? ''),
                        isset($data['comment']) ? (string) $data['comment'] : null,
                        (string) ($data['reason'] ?? ''),
                    );
                    $this->redirect(BackofficeUserResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
            Action::make('revertParticipationRole')
                ->label('Откатить изменение роли участия')
                ->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (): bool => BackofficeUserResource::canEdit($this->getRecord())
                    && AuditLog::query()->where('auditable_type', User::class)
                        ->where('auditable_id', $this->getRecord()->id)
                        ->where('event', 'admin_participation_role_changed')->exists())
                ->modalHeading('Откат изменения роли участия')
                ->modalDescription('Если статус или комментарий изменились позднее, откат будет отклонён.')
                ->modalSubmitActionLabel('Подтвердить откат')
                ->requiresConfirmation()
                ->schema([
                    Select::make('revision_id')->label('Ревизия роли')->required()
                        ->options(fn (): array => AuditLog::query()
                            ->where('auditable_type', User::class)
                            ->where('auditable_id', $this->getRecord()->id)
                            ->where('event', 'admin_participation_role_changed')
                            ->latest('id')->limit(30)->get()
                            ->mapWithKeys(fn (AuditLog $log): array => [
                                $log->id => '#'.$log->id.' · '.($log->metadata['participation_role'] ?? 'роль')
                                    .' · '.$log->created_at?->format('d.m.Y H:i'),
                            ])->all()),
                    Textarea::make('reason')->label('Основание отката')
                        ->required()->minLength(10)->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    app(AdminRevertParticipationRoleHandler::class)->handle(
                        $actor, $this->getRecord(), (int) $data['revision_id'], (string) ($data['reason'] ?? ''),
                    );
                    $this->redirect(BackofficeUserResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
            Action::make('editVenueContract')
                ->label('Изменить договорные права площадки')
                ->icon('heroicon-o-building-office-2')
                ->visible(fn (): bool => BackofficeUserResource::canEdit($this->getRecord())
                    && app(VenueMembershipAccess::class)->activeVenueMembershipsFor($this->getRecord())->isNotEmpty())
                ->modalHeading('Контекстная роль управления площадкой')
                ->modalDescription('Меняется только действующий договор. Владелец не может быть переназначен. Права зала не становятся правами всей площадки.')
                ->modalSubmitActionLabel('Подтвердить изменение')
                ->requiresConfirmation()
                ->fillForm(function (array $arguments): array {
                    $membership = app(VenueMembershipAccess::class)
                        ->activeVenueMembershipsFor($this->getRecord())
                        ->firstWhere('id', (int) ($arguments['membership_id'] ?? 0));
                    return $membership === null ? [] : [
                        'membership_id' => $membership->id,
                        'access_level' => $membership->access_level,
                        'permissions' => $membership->contract->permissions->pluck('permission')->all(),
                    ];
                })
                ->schema([
                    Select::make('membership_id')->label('Действующий договор')
                        ->options(fn (): array => app(VenueMembershipAccess::class)
                            ->activeVenueMembershipsFor($this->getRecord())
                            ->filter(fn ($membership): bool => VenueMembershipAccessLevelEnum::tryFrom((string) $membership->access_level)?->isCommercialRole()
                                && $membership->access_level !== VenueMembershipAccessLevelEnum::OWNER->value)
                            ->mapWithKeys(fn ($membership): array => [
                                $membership->id => 'Договор #'.$membership->contract_id.' · '.($membership->contract?->name ?? 'Площадка'),
                            ])->all())
                        ->required()->native(false),
                    Select::make('access_level')->label('Специализация')
                        ->options(collect(VenueMembershipAccessLevelEnum::cases())
                            ->filter(fn (VenueMembershipAccessLevelEnum $level): bool => $level->isCommercialRole()
                                && $level !== VenueMembershipAccessLevelEnum::OWNER)
                            ->mapWithKeys(fn (VenueMembershipAccessLevelEnum $level): array => [$level->value => $level->label()])
                            ->all())->required()->native(false),
                    CheckboxList::make('permissions')->label('Разрешения договора')
                        ->options(collect(VenuePermissionEnum::cases())
                            ->mapWithKeys(fn (VenuePermissionEnum $permission): array => [
                                $permission->value => $permission->label(),
                            ])->all())->columns(2),
                    Textarea::make('reason')->label('Основание изменения')
                        ->required()->minLength(10)->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    app(AdminChangeVenueMembershipHandler::class)->handle(
                        $actor, $this->getRecord(),
                        (int) ($data['membership_id'] ?? 0),
                        (string) ($data['access_level'] ?? ''),
                        (array) ($data['permissions'] ?? []),
                        (string) ($data['reason'] ?? ''),
                    );
                    $this->redirect(BackofficeUserResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
            Action::make('revertVenueContract')
                ->label('Откатить договорные права')
                ->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (): bool => BackofficeUserResource::canEdit($this->getRecord())
                    && AuditLog::query()->where('auditable_type', User::class)
                        ->where('auditable_id', $this->getRecord()->id)
                        ->where('event', 'admin_venue_membership_changed')->exists())
                ->modalHeading('Откат изменения договора')
                ->modalDescription('Разрешён только для активного договора, если роль и права всё ещё совпадают с выбранной ревизией.')
                ->modalSubmitActionLabel('Подтвердить откат')
                ->requiresConfirmation()
                ->schema([
                    Select::make('revision_id')->label('Изменение договора')->required()
                        ->options(fn (): array => AuditLog::query()
                            ->where('auditable_type', User::class)
                            ->where('auditable_id', $this->getRecord()->id)
                            ->where('event', 'admin_venue_membership_changed')
                            ->latest('id')->limit(30)->get()
                            ->mapWithKeys(fn (AuditLog $log): array => [
                                $log->id => '#'.$log->id.' · договор #'.($log->metadata['contract_id'] ?? '?')
                                    .' · '.$log->created_at?->format('d.m.Y H:i'),
                            ])->all()),
                    Textarea::make('reason')->label('Основание отката')
                        ->required()->minLength(10)->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    app(AdminRevertVenueMembershipHandler::class)->handle(
                        $actor, $this->getRecord(), (int) $data['revision_id'], (string) ($data['reason'] ?? ''),
                    );
                    $this->redirect(BackofficeUserResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
            Action::make('revertUserPermissions')
                ->label('Откатить изменение системных прав')
                ->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (): bool => BackofficeUserResource::canEdit($this->getRecord())
                    && AuditLog::query()->where('auditable_type', User::class)
                        ->where('auditable_id', $this->getRecord()->id)
                        ->where('event', 'admin_user_permissions_changed')->exists())
                ->modalHeading('Откат системных разрешений')
                ->modalDescription('Откат допустим только при неизменной системной роли и отсутствии более поздних изменений действующих прав.')
                ->modalSubmitActionLabel('Подтвердить откат')
                ->requiresConfirmation()
                ->schema([
                    Select::make('revision_id')->label('Ревизия прав')->required()
                        ->options(fn (): array => AuditLog::query()
                            ->where('auditable_type', User::class)
                            ->where('auditable_id', $this->getRecord()->id)
                            ->where('event', 'admin_user_permissions_changed')
                            ->latest('id')->limit(30)->get()
                            ->mapWithKeys(fn (AuditLog $log): array => [
                                $log->id => '#'.$log->id.' · '.$log->created_at?->format('d.m.Y H:i'),
                            ])->all()),
                    Textarea::make('reason')->label('Основание отката')
                        ->required()->minLength(10)->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    app(AdminRevertUserPermissionSnapshotHandler::class)->handle(
                        $actor, $this->getRecord(), (int) $data['revision_id'], (string) ($data['reason'] ?? ''),
                    );
                    $this->redirect(BackofficeUserResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
            Action::make('revertSystemRole')
                ->label('Откатить изменение системной роли')
                ->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (): bool => BackofficeUserResource::canEdit($this->getRecord())
                    && AuditLog::query()->where('auditable_type', User::class)
                        ->where('auditable_id', $this->getRecord()->id)
                        ->where('event', 'admin_system_role_changed')->exists())
                ->modalHeading('Откат системной роли')
                ->modalDescription('Откат допустим только при неизменных правах и специализациях. Ранее отозванные права не восстановятся автоматически.')
                ->modalSubmitActionLabel('Подтвердить откат')
                ->requiresConfirmation()
                ->schema([
                    Select::make('revision_id')->label('Ревизия системной роли')->required()
                        ->options(fn (): array => AuditLog::query()
                            ->where('auditable_type', User::class)
                            ->where('auditable_id', $this->getRecord()->id)
                            ->where('event', 'admin_system_role_changed')
                            ->latest('id')->limit(30)->get()
                            ->mapWithKeys(fn (AuditLog $log): array => [
                                $log->id => '#'.$log->id.' · '.$log->created_at?->format('d.m.Y H:i'),
                            ])->all()),
                    Textarea::make('reason')->label('Основание отката')
                        ->required()->minLength(10)->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    app(AdminRevertUserSystemRoleHandler::class)->handle(
                        $actor, $this->getRecord(), (int) $data['revision_id'], (string) ($data['reason'] ?? ''),
                    );
                    $this->redirect(BackofficeUserResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
            Action::make('changeSystemRole')
                ->label('Изменить системную роль')
                ->icon('heroicon-o-user-circle')
                ->visible(fn (): bool => auth('web')->user()?->can(
                    'manage-user-system-role', $this->getRecord(),
                ) ?? false)
                ->modalHeading('Назначение системной роли')
                ->modalDescription('Можно назначить только роль ниже собственной. Роль «Пользователь» снимает административную роль. Глобальные права сохраняются отдельно.')
                ->modalSubmitActionLabel('Подтвердить роль')
                ->requiresConfirmation()
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
                    Textarea::make('reason')->label('Основание изменения')
                        ->required()->minLength(10)->maxLength(500),
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
                    app(AdminChangeUserSystemRoleWithAuditHandler::class)
                        ->handle($actor, $target, $role, (string) ($data['reason'] ?? ''));

                    // Reload effective defaults after changing the user's role.
                    $this->redirect(BackofficeUserResource::getUrl('edit', ['record' => $target]));
                }),
            Action::make('revertStaffSpecializations')
                ->label('Откатить специализации менеджера')
                ->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (): bool => BackofficeUserResource::canEdit($this->getRecord())
                    && AuditLog::query()->where('auditable_type', User::class)
                        ->where('auditable_id', $this->getRecord()->id)
                        ->where('event', 'admin_staff_specializations_changed')->exists())
                ->modalHeading('Откат назначений менеджера')
                ->modalDescription('Проверим роль, должность и действующие специализации перед возвратом прав.')
                ->modalSubmitActionLabel('Подтвердить откат')
                ->requiresConfirmation()
                ->schema([
                    Select::make('revision_id')->label('Ревизия специализаций')->required()
                        ->options(fn (): array => AuditLog::query()
                            ->where('auditable_type', User::class)
                            ->where('auditable_id', $this->getRecord()->id)
                            ->where('event', 'admin_staff_specializations_changed')
                            ->latest('id')->limit(30)->get()
                            ->mapWithKeys(fn (AuditLog $log): array => [
                                $log->id => '#'.$log->id.' · '.$log->created_at?->format('d.m.Y H:i'),
                            ])->all()),
                    Textarea::make('reason')->label('Основание отката')
                        ->required()->minLength(10)->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    app(AdminRevertStaffSpecializationsHandler::class)->handle(
                        $actor, $this->getRecord(), (int) $data['revision_id'], (string) ($data['reason'] ?? ''),
                    );
                    $this->redirect(BackofficeUserResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
            Action::make('assignSpecializations')
                ->label('Назначить специализации')
                ->icon('heroicon-o-identification')
                ->visible(fn (): bool => auth('web')->user() !== null
                    && app(StaffSpecializationManager::class)->mayManage(auth('web')->user(), $this->getRecord()))
                ->modalHeading('Специализации менеджера')
                ->modalDescription('Можно выбрать несколько профилей. Каждое изменение требует основания и фиксируется в журнале.')
                ->modalSubmitActionLabel('Подтвердить назначение')
                ->requiresConfirmation()
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
                    Textarea::make('reason')->label('Основание изменения')
                        ->required()->minLength(10)->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    $ids = $data['specialization_ids'] ?? [];
                    if (! is_array($ids)) {
                        throw ValidationException::withMessages(['specialization_ids' => 'Неверный список специализаций.']);
                    }
                    app(AdminUpdateStaffSpecializationsWithAuditHandler::class)->handle(
                        $actor, $this->getRecord(), $ids,
                        (string) ($data['staff_title'] ?? ''), (string) ($data['reason'] ?? ''),
                    );
                    $this->redirect(BackofficeUserResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $target = $this->getRecord()->canonical();
        $actor = auth('web')->user()?->canonical();
        $profiles = app(StaffPermissionProfiles::class);
        $checker = app(UserOperationalPermissionChecker::class);
        $global = app(GlobalOperationalPermissionManager::class);
        $groups = [
            'creation_permissions' => $global->creationPermissions(),
            'privileged_permissions' => $global->privilegedPermissions(),
            'backoffice_permissions' => Permission::backofficeCases(),
        ];
        foreach ($groups as $field => $permissions) {
            $data[$field] = [];
            $data['locked_'.$field] = [];
            foreach ($permissions as $permission) {
                $effective = $checker->allows($target, $permission);
                $editable = $actor !== null
                    && $profiles->allowedForRole($target->system_role, $permission)
                    && ! $profiles->inherited($target, $permission)
                    && ($actor->system_role === UserSystemRoleEnum::SUPERADMIN
                        || $checker->allows($actor, $permission));
                if ($effective) {
                    $data[$editable ? $field : 'locked_'.$field][] = $permission->value;
                }
            }
        }

        return $data;
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->requiresConfirmation()
            ->modalHeading('Подтвердить изменение прав')
            ->modalDescription('Назначения изменятся только после подтверждения. Основание и состояние до/после будут записаны в аудит.');
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $actor = auth('web')->user()?->canonical();
        abort_unless($actor !== null && BackofficeUserResource::canEdit($record), 403);

        // Never accept hidden/forged fields as user or role attributes.
        // The use case performs a second permission check under a transaction.
        app(AdminUpdateUserPermissionSnapshotHandler::class)->handle(
            $actor,
            $record,
            (array) ($data['creation_permissions'] ?? []),
            (array) ($data['privileged_permissions'] ?? []),
            (array) ($data['backoffice_permissions'] ?? []),
            (string) ($data['change_reason'] ?? ''),
        );

        return $record->refresh();
    }
}
