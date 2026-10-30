<?php

namespace App\Filament\Backoffice\Resources\BackofficeUsers\Pages;

use App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Finance\Application\Services\BackofficeUserWalletFunding;
use App\Modules\Finance\Application\Services\RubleAmountParser;
use App\Modules\Finance\Application\Services\SuperadminWalletBonusGrant;
use App\Modules\Finance\Domain\Exceptions\WalletException;
use App\Modules\Identity\Application\Services\GlobalOperationalPermissionManager;
use App\Modules\Identity\Application\Services\StaffSpecializationManager;
use App\Modules\Identity\Application\Services\StaffPermissionProfiles;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Application\Services\SuperadminSelfEditAccess;
use App\Modules\Identity\Domain\Models\ManagerSpecialization;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
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
use App\Modules\Identity\Application\UseCases\AdminAssignParticipationRoleHandler;
use App\Modules\Identity\Application\UseCases\AdminUpdateUserStatusHandler;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
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
use Illuminate\Support\Str;

final class EditBackofficeUser extends EditRecord
{
    protected static string $resource = BackofficeUserResource::class;

    public ?int $selectedParticipationRoleId = null;
    public ?string $roleComment = null;
    public ?string $rolePlayerHeight = null;
    public ?string $rolePlayerWeight = null;
    public ?string $rolePlayerBodyType = null;
    public ?string $rolePlayerExperience = null;
    public ?string $rolePlayerComment = null;

    public function selectParticipationRole(int $roleId): void
    {
        abort_unless(BackofficeUserResource::canEdit($this->getRecord()), 403);
        $role = $this->getRecord()->participationRoles(false)->findOrFail($roleId);
        $this->selectedParticipationRoleId = $role->id;
        $this->roleComment = $role->comment;
        if ($role->role === UserParticipationRoleEnum::PLAYER) {
            $profile = $this->getRecord()->playerProfile;
            $this->rolePlayerHeight = $profile?->height_cm === null ? null : (string) $profile->height_cm;
            $this->rolePlayerWeight = $profile?->weight_kg === null ? null : (string) $profile->weight_kg;
            $this->rolePlayerBodyType = $profile?->body_type?->value;
            $this->rolePlayerExperience = $profile?->experience_started_year === null ? null : (string) $profile->experience_started_year;
            $this->rolePlayerComment = $profile?->comment;
        } else {
            $this->rolePlayerHeight = $this->rolePlayerWeight = $this->rolePlayerBodyType
                = $this->rolePlayerExperience = $this->rolePlayerComment = null;
        }
    }

    /** Role changes intentionally have their own save operation. Changing role
     * defaults must not accidentally persist stale permission checkboxes. */
    protected function getHeaderActions(): array
    {
        return [
            // Hidden in the main page header: buttons in the Wallet tab
            // mount these guarded actions without leaving the user editor.
            Action::make('grantOwnWalletBonus')
                ->label('Пополнить свой бонусный баланс')
                ->extraAttributes(['style' => 'display:none'])
                ->visible(function (): bool {
                    $actor = auth('web')->user();
                    return $actor !== null
                        && (int) $actor->canonical()->id === (int) $this->getRecord()->canonical()->id
                        && app(SuperadminWalletBonusGrant::class)->allowed($actor);
                })
                ->modalHeading('Пополнение бонусного баланса superadmin')
                ->modalDescription('Введите сумму и текущий пароль superadmin. Будет создана неизменяемая финансовая операция.')
                ->modalSubmitActionLabel('Подтвердить начисление')
                ->schema([
                    TextInput::make('grant_amount')->label('Сумма, ₽')->required()
                        ->maxLength(32)->placeholder('5000,00'),
                    TextInput::make('grant_password')->label('Текущий пароль superadmin')
                        ->password()->required()->maxLength(255),
                    Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
                ])
                ->action(function (array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null
                        && (int) $actor->canonical()->id === (int) $this->getRecord()->canonical()->id
                        && app(SuperadminWalletBonusGrant::class)->allowed($actor), 403);

                    try {
                        $amount = app(RubleAmountParser::class)->parse((string) ($data['grant_amount'] ?? ''));
                    } catch (WalletException $exception) {
                        throw ValidationException::withMessages(['grant_amount' => $exception->getMessage()]);
                    }
                    try {
                        app(SuperadminWalletBonusGrant::class)->grant(
                            user: $actor,
                            currentPassword: (string) ($data['grant_password'] ?? ''),
                            amountMinor: $amount,
                            idempotencyKey: (string) ($data['idempotency_key'] ?? ''),
                        );
                    } catch (WalletException $exception) {
                        throw ValidationException::withMessages(['grant_password' => $exception->getMessage()]);
                    }
                    Notification::make()->success()->title('Бонусный баланс пополнен')->send();
                }),
            Action::make('fundUserWalletBonus')
                ->label('Начислить бонусы пользователю')
                ->extraAttributes(['style' => 'display:none'])
                ->visible(function (): bool {
                    $actor = auth('web')->user();
                    return $actor !== null
                        && app(BackofficeUserWalletFunding::class)->canFund($actor, $this->getRecord());
                })
                ->modalHeading('Перевод бонусов из кошелька superadmin')
                ->modalDescription('Сумма списывается с выбранного бонусного баланса superadmin и зачисляется пользователю. Источник, исполнитель и основание сохраняются в истории.')
                ->modalSubmitActionLabel('Перевести бонусы')
                ->schema([
                    Select::make('source_superadmin_user_id')
                        ->label('Кошелёк-источник (superadmin)')->required()
                        ->native(false)
                        ->options(fn (): array => auth('web')->user() === null ? []
                            : app(BackofficeUserWalletFunding::class)->sourceOptions(auth('web')->user()))
                        ->default(fn (): ?int => auth('web')->user()?->canonical()->system_role === UserSystemRoleEnum::SUPERADMIN
                            ? (int) auth('web')->user()->canonical()->id : null),
                    TextInput::make('amount')->label('Сумма бонусов, ₽')->required()
                        ->maxLength(32)->placeholder('500,00'),
                    Textarea::make('reason')->label('Основание начисления')
                        ->required()->minLength(10)->maxLength(500),
                    Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
                ])
                ->action(function (array $data): void {
                    $actor = auth('web')->user();
                    $target = $this->getRecord();
                    $funding = app(BackofficeUserWalletFunding::class);
                    abort_unless($actor !== null && $funding->canFund($actor, $target), 403);

                    try {
                        $amount = app(RubleAmountParser::class)->parse((string) ($data['amount'] ?? ''));
                    } catch (WalletException $exception) {
                        throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
                    }
                    try {
                        $result = $funding->fund(
                            actor: $actor,
                            recipient: $target,
                            sourceSuperadminId: (int) ($data['source_superadmin_user_id'] ?? 0),
                            amountMinor: $amount,
                            reason: (string) ($data['reason'] ?? ''),
                            idempotencyKey: (string) ($data['idempotency_key'] ?? ''),
                        );
                    } catch (WalletException $exception) {
                        throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
                    }

                    Notification::make()->success()
                        ->title($result->replayed ? 'Этот перевод уже выполнен' : 'Бонусы перечислены пользователю')
                        ->send();
                }),
            Action::make('saveGeneralInline')
                ->label('Сохранить общие данные')
                ->extraAttributes(['style' => 'display:none'])
                ->visible(fn (): bool => BackofficeUserResource::canEdit($this->getRecord())
                    && auth('web')->user()?->canonical()->system_role === UserSystemRoleEnum::SUPERADMIN
                    && ! SuperadminSelfEditAccess::allows(auth('web')->user(), $this->getRecord()))
                ->modalHeading('Подтвердить изменение статуса')
                ->modalSubmitActionLabel('Подтвердить')
                ->requiresConfirmation()
                ->schema([Textarea::make('reason')->label('Основание изменения')->required()->minLength(10)->maxLength(500)])
                ->action(function (array $data): void {
                    $actor = auth('web')->user()?->canonical();
                    abort_unless($actor?->system_role === UserSystemRoleEnum::SUPERADMIN, 403);
                    $status = UserStatusEnum::tryFrom((string) ($this->form->getState()['account_status'] ?? ''));
                    if ($status === null) {
                        throw ValidationException::withMessages(['account_status' => 'Неизвестный статус пользователя.']);
                    }
                    $before = $this->getRecord()->fresh()->status;
                    if ($before === $status) {
                        throw ValidationException::withMessages(['account_status' => 'Статус не изменён.']);
                    }
                    DB::transaction(function () use ($actor, $status, $before, $data): void {
                        app(AdminUpdateUserStatusHandler::class)->handle($actor, $this->getRecord()->id, $status);
                        AuditLog::query()->create([
                            'actor_id' => $actor->id,
                            'auditable_type' => User::class,
                            'auditable_id' => $this->getRecord()->id,
                            'event' => 'admin_user_status_changed',
                            'old_values' => ['status' => $before->value],
                            'new_values' => ['status' => $status->value],
                            'metadata' => ['reason' => (string) $data['reason']],
                        ]);
                    });
                    $this->getRecord()->refresh();
                }),
            Action::make('savePersonalInline')
                ->label('Сохранить профиль')
                ->extraAttributes(['style' => 'display:none'])
                ->visible(fn (): bool => BackofficeUserResource::canEdit($this->getRecord()))
                ->modalHeading('Подтвердить изменение личных данных')
                ->modalSubmitActionLabel('Подтвердить')
                ->requiresConfirmation()
                ->schema([Textarea::make('reason')->label('Основание изменения')->required()->minLength(10)->maxLength(500)])
                ->action(function (array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    $state = $this->form->getState();
                    $values = [];
                    foreach (AdminEditPersonalProfileHandler::EDITABLE as $field) {
                        $key = 'profile_'.$field;
                        $values[$field] = ($state[$key] ?? null) === '' ? null : ($state[$key] ?? null);
                    }
                    app(AdminEditPersonalProfileHandler::class)->handle(
                        $actor, $this->getRecord(), $values, (string) $data['reason'],
                    );
                    $this->getRecord()->refresh();
                }),
            Action::make('saveSystemRoleInline')
                ->label('Сохранить системную роль')
                ->extraAttributes(['style' => 'display:none'])
                ->visible(fn (): bool => auth('web')->user()?->can('manage-user-system-role', $this->getRecord()) ?? false)
                ->modalHeading('Подтвердить системную роль')
                ->modalSubmitActionLabel('Подтвердить')
                ->requiresConfirmation()
                ->schema([Textarea::make('reason')->label('Основание изменения')->required()->minLength(10)->maxLength(500)])
                ->action(function (array $data): void {
                    $actor = auth('web')->user()?->canonical();
                    $target = $this->getRecord();
                    abort_unless($actor !== null && $actor->can('manage-user-system-role', $target), 403);
                    $state = $this->form->getState();
                    $role = UserSystemRoleEnum::tryFrom((string) ($state['requested_system_role'] ?? ''));
                    if ($role === null || in_array($role, [
                        UserSystemRoleEnum::SUPERADMIN, UserSystemRoleEnum::SYSTEM,
                        UserSystemRoleEnum::MODERATOR, UserSystemRoleEnum::EDITOR,
                    ], true) || $role->numericValue() >= $actor->system_role->numericValue()) {
                        throw ValidationException::withMessages(['requested_system_role' => 'Недопустимая системная роль.']);
                    }
                    if ($role === $target->system_role) {
                        throw ValidationException::withMessages(['requested_system_role' => 'Системная роль не изменилась.']);
                    }
                    app(AdminChangeUserSystemRoleWithAuditHandler::class)->handle(
                        $actor, $target, $role, (string) $data['reason'],
                    );
                    $target->refresh();
                    // Role changes also change the set of permitted grants. Refresh the
                    // editor to avoid accidental submission of old permission checkboxes.
                    $this->fillForm();
                }),
            Action::make('saveSpecializationsInline')
                ->label('Сохранить специализации')
                ->extraAttributes(['style' => 'display:none'])
                ->visible(fn (): bool => auth('web')->user() !== null
                    && app(StaffSpecializationManager::class)->mayManage(auth('web')->user(), $this->getRecord()))
                ->modalHeading('Подтвердить специализации менеджера')
                ->modalSubmitActionLabel('Подтвердить')
                ->requiresConfirmation()
                ->schema([Textarea::make('reason')->label('Основание изменения')->required()->minLength(10)->maxLength(500)])
                ->action(function (array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null
                        && app(StaffSpecializationManager::class)->mayManage($actor, $this->getRecord()), 403);
                    $state = $this->form->getState();
                    $ids = $state['manager_specialization_ids'] ?? [];
                    if (! is_array($ids)) {
                        throw ValidationException::withMessages(['manager_specialization_ids' => 'Некорректный список специализаций.']);
                    }
                    app(AdminUpdateStaffSpecializationsWithAuditHandler::class)->handle(
                        $actor, $this->getRecord(), $ids,
                        (string) ($state['manager_staff_title'] ?? ''), (string) $data['reason'],
                    );
                    $this->getRecord()->refresh();
                    $this->fillForm();
                }),
            Action::make('savePermissionsInline')
                ->label('Сохранить системные разрешения')
                ->extraAttributes(['style' => 'display:none'])
                ->visible(fn (): bool => BackofficeUserResource::canEdit($this->getRecord())
                    && ! SuperadminSelfEditAccess::allows(auth('web')->user(), $this->getRecord()))
                ->modalHeading('Подтвердить изменение системных прав')
                ->modalDescription('Индивидуальное разрешение или запрет перекрывает обычные предустановки роли. Ограничения иерархии сохраняются.')
                ->modalSubmitActionLabel('Подтвердить')
                ->requiresConfirmation()
                ->schema([Textarea::make('reason')->label('Основание изменения')->required()->minLength(10)->maxLength(500)])
                ->action(function (array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null && BackofficeUserResource::canEdit($this->getRecord()), 403);
                    $state = $this->form->getState();
                    app(AdminUpdateUserPermissionSnapshotHandler::class)->handle(
                        $actor, $this->getRecord(),
                        (array) ($state['creation_permissions'] ?? []),
                        (array) ($state['privileged_permissions'] ?? []),
                        (array) ($state['backoffice_permissions'] ?? []),
                        (string) $data['reason'],
                    );
                    $this->getRecord()->refresh();
                }),
            Action::make('addParticipationRole')
                ->label('Добавить роль')
                ->extraAttributes(['style' => 'display:none'])
                ->visible(fn (): bool => BackofficeUserResource::canEdit($this->getRecord()))
                ->modalHeading('Добавить роль участия')
                ->modalDescription('В списке только роли, которые ещё не назначены, включая отключённые.')
                ->modalSubmitActionLabel('Подтвердить')
                ->requiresConfirmation()
                ->schema([
                    Select::make('role')->label('Роль')->native(false)->required()
                        ->options(function (): array {
                            $existing = $this->getRecord()->participationRoles(false)->pluck('role')
                                ->map(fn ($role): string => $role instanceof UserParticipationRoleEnum ? $role->value : (string) $role)->all();
                            $options = [];
                            foreach (UserParticipationRoleEnum::cases() as $role) {
                                if (! in_array($role->value, $existing, true)) {
                                    $options[$role->value] = $role->label();
                                }
                            }
                            return $options;
                        }),
                    Textarea::make('reason')->label('Основание назначения')->required()->minLength(10)->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    $audit = app(AdminAssignParticipationRoleHandler::class)->handle(
                        $actor, $this->getRecord(), (string) $data['role'], (string) $data['reason'],
                    );
                    $this->selectParticipationRole((int) $audit->metadata['participation_role_id']);
                }),
            Action::make('toggleParticipationRole')
                ->label('Изменить состояние роли')
                ->extraAttributes(['style' => 'display:none'])
                ->visible(fn (): bool => BackofficeUserResource::canEdit($this->getRecord()))
                ->modalHeading('Подтвердить отключение или восстановление роли')
                ->modalDescription('Данные роли и профиль сохраняются. Операция будет зафиксирована в истории.')
                ->modalSubmitActionLabel('Подтвердить')
                ->requiresConfirmation()
                ->schema([Textarea::make('reason')->label('Основание изменения')->required()->minLength(10)->maxLength(500)])
                ->action(function (array $arguments, array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    $role = $this->getRecord()->participationRoles(false)->findOrFail((int) ($arguments['role_id'] ?? 0));
                    $next = $role->status === UserParticipationRoleStatusEnum::ACTIVE
                        ? UserParticipationRoleStatusEnum::INACTIVE : UserParticipationRoleStatusEnum::ACTIVE;
                    app(AdminEditParticipationRoleHandler::class)->handle(
                        $actor, $this->getRecord(), $role->id, $next->value,
                        $role->comment, (string) $data['reason'],
                    );
                    $this->selectParticipationRole($role->id);
                }),
            Action::make('saveParticipationRoleInline')
                ->label('Сохранить роль')
                ->extraAttributes(['style' => 'display:none'])
                ->visible(fn (): bool => BackofficeUserResource::canEdit($this->getRecord()))
                ->modalHeading('Подтвердить изменения роли')
                ->modalSubmitActionLabel('Подтвердить')
                ->requiresConfirmation()
                ->schema([Textarea::make('reason')->label('Основание изменения')->required()->minLength(10)->maxLength(500)])
                ->action(function (array $data): void {
                    $actor = auth('web')->user();
                    abort_unless($actor !== null, 403);
                    $role = $this->getRecord()->participationRoles(false)->findOrFail((int) $this->selectedParticipationRoleId);
                    if ($role->status !== UserParticipationRoleStatusEnum::ACTIVE) {
                        throw ValidationException::withMessages(['role' => 'Сначала активируйте роль.']);
                    }
                    $reason = (string) $data['reason'];
                    $comment = trim((string) ($this->roleComment ?? '')) ?: null;
                    $playerValues = [
                        'height_cm' => $this->rolePlayerHeight === null || $this->rolePlayerHeight === '' ? null : (int) $this->rolePlayerHeight,
                        'weight_kg' => $this->rolePlayerWeight === null || $this->rolePlayerWeight === '' ? null : (int) $this->rolePlayerWeight,
                        'body_type' => $this->rolePlayerBodyType === '' ? null : $this->rolePlayerBodyType,
                        'experience_started_year' => $this->rolePlayerExperience === null || $this->rolePlayerExperience === '' ? null : (int) $this->rolePlayerExperience,
                        'comment' => $this->rolePlayerComment === '' ? null : $this->rolePlayerComment,
                    ];
                    DB::transaction(function () use ($actor, $role, $comment, $playerValues, $reason): void {
                        $changed = false;
                        if ($role->comment !== $comment) {
                            app(AdminEditParticipationRoleHandler::class)->handle(
                                $actor, $this->getRecord(), $role->id,
                                UserParticipationRoleStatusEnum::ACTIVE->value, $comment, $reason,
                            );
                            $changed = true;
                        }
                        if ($role->role === UserParticipationRoleEnum::PLAYER) {
                            $profile = $this->getRecord()->playerProfile;
                            $changedValues = [];
                            foreach ($playerValues as $field => $value) {
                                $raw = $profile?->getRawOriginal($field);
                                if (($raw === null && $value === null)
                                    || ($raw !== null && $value !== null && ((string) $raw === (string) $value
                                        || ($field === 'weight_kg' && (float) $raw === (float) $value)))) {
                                    continue;
                                }
                                $changedValues[$field] = $value;
                            }
                            if ($changedValues !== []) {
                                app(AdminEditPlayerProfileHandler::class)->handle(
                                    $actor, $this->getRecord(), $changedValues, $reason,
                                );
                                $changed = true;
                            }
                        }
                        if (! $changed) {
                            throw ValidationException::withMessages(['role' => 'Параметры роли не изменены.']);
                        }
                    });
                    $this->selectParticipationRole($role->id);
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
                ->extraAttributes(['style' => 'display:none'])
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
                ->extraAttributes(['style' => 'display:none'])
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
        $data['requested_system_role'] = $target->system_role->value;
        $data['manager_staff_title'] = $target->staff_title;
        $data['manager_specialization_ids'] = $target->managerSpecializations()
            ->pluck('manager_specializations.id')->map(fn ($id): string => (string) $id)->all();
        $data['account_username'] = $target->username;
        $data['account_status'] = $target->status->value;
        $profile = $target->profile;
        foreach (AdminEditPersonalProfileHandler::EDITABLE as $field) {
            $value = $profile?->$field;
            $data['profile_'.$field] = $field === 'gender' ? $value?->value
                : ($field === 'birth_date' ? $value?->toDateString() : $value);
        }
        foreach ($groups as $field => $permissions) {
            $data[$field] = [];
            $data['locked_'.$field] = [];
            foreach ($permissions as $permission) {
                $effective = $checker->allows($target, $permission);
                $editable = $actor !== null
                    && $profiles->allowedForRole($target->system_role, $permission)
                    && ($actor->system_role === UserSystemRoleEnum::SUPERADMIN
                        || $checker->allows($actor, $permission));
                if ($effective) {
                    $data[$editable ? $field : 'locked_'.$field][] = $permission->value;
                }
            }
        }

        return $data;
    }

    /** Saving is tab-scoped: every editable tab has its own confirmed action. */
    protected function getFormActions(): array
    {
        return [];
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
