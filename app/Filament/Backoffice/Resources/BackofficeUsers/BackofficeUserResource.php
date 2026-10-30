<?php

namespace App\Filament\Backoffice\Resources\BackofficeUsers;

use App\Filament\Backoffice\Resources\BackofficeUsers\Pages\EditBackofficeUser;
use App\Filament\Backoffice\Resources\BackofficeUsers\Pages\EditBackofficeUserRole;
use App\Filament\Backoffice\Resources\BackofficeUsers\Pages\ListBackofficeUsers;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Application\Services\GlobalOperationalPermissionManager;
use App\Modules\Identity\Application\Services\StaffPermissionProfiles;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\ManagerSpecialization;
use App\Modules\Identity\Application\Services\StaffSpecializationManager;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class BackofficeUserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $recordTitleAttribute = 'username';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return 'Пользователи';
    }

    public static function getModelLabel(): string
    {
        return 'пользователь';
    }

    public static function getPluralModelLabel(): string
    {
        return 'пользователи';
    }

    private static function permissions(): BackofficePermissionManager
    {
        return app(BackofficePermissionManager::class);
    }

    public static function canViewAny(): bool
    {
        $actor = auth('web')->user();

        return $actor !== null && app(GlobalOperationalPermissionManager::class)->mayManage($actor);
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof User && self::canEdit($record);
    }

    public static function canEdit(Model $record): bool
    {
        $actor = auth('web')->user();

        return $actor !== null && $record instanceof User
            && app(GlobalOperationalPermissionManager::class)->mayManage($actor, $record)
            && app(UserOperationalPermissionChecker::class)->allows($actor, Permission::EDIT_USER_PERSONAL_DATA);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->whereNull('canonical_user_id');

        // A system admin can only delegate to lower system roles. A superadmin
        // can manage admins too. Never list superadmin/system as grant targets.
        $role = auth('web')->user()?->canonical()->system_role;
        if ($role === UserSystemRoleEnum::SUPERADMIN) {
            return $query->whereNotIn('system_role', [
                UserSystemRoleEnum::SUPERADMIN->value, UserSystemRoleEnum::SYSTEM->value,
            ]);
        }
        if ($role === UserSystemRoleEnum::ADMIN) {
            return $query->whereIn('system_role', [
                UserSystemRoleEnum::MANAGER->value,
                UserSystemRoleEnum::MODERATOR->value,
                UserSystemRoleEnum::EDITOR->value,
                UserSystemRoleEnum::USER->value,
            ]);
        }

        return $query->whereRaw('1 = 0');
    }

    private static function cannotDelegate(?User $record, Permission $permission): bool
    {
        $actor = auth('web')->user()?->canonical();
        if ($actor === null || $record === null) {
            return true;
        }
        $profiles = app(StaffPermissionProfiles::class);
        return ! $profiles->allowedForRole($record->system_role, $permission)
            || ($actor->system_role !== UserSystemRoleEnum::SUPERADMIN
                && ! app(UserOperationalPermissionChecker::class)->allows($actor, $permission));
    }

    /** @return array<string, string> */
    private static function selectablePermissions(array $permissions, ?User $record, bool $locked): array
    {
        $options = [];
        foreach ($permissions as $permission) {
            if (self::cannotDelegate($record, $permission) === $locked) {
                $options[$permission->value] = $permission->label();
            }
        }

        return $options;
    }

    public static function form(Schema $schema): Schema
    {
        $global = app(GlobalOperationalPermissionManager::class);

        return $schema->components([
            Tabs::make('Редактирование пользователя')
                ->persistTabInQueryString()
                ->tabs(function (?User $record) use ($global): array {
                    return [
                        Tab::make('Общее')
                            ->icon('heroicon-o-information-circle')
                            ->schema([
                                Section::make('Общая информация')
                                    ->description('Регистрационные идентификаторы и даты доступны для просмотра. Статус можно изменить в этом разделе только суперадминистратору.')
                                    ->schema([
                                        Placeholder::make('user_id_info')->label('ID')->content(fn (?User $record): string => (string) ($record?->id ?? '—')),
                                        TextInput::make('account_username')->label('Логин')->disabled()->dehydrated(false),
                                        Select::make('account_status')->label('Статус аккаунта')
                                            ->options(collect(\App\Modules\Identity\Domain\Enums\UserStatusEnum::cases())->mapWithKeys(
                                                fn ($status): array => [$status->value => $status->label()],
                                            )->all())
                                            ->disabled(fn (): bool => auth('web')->user()?->canonical()->system_role !== UserSystemRoleEnum::SUPERADMIN)
                                            ->dehydrated(),
                                        Placeholder::make('registered_at_info')->label('Зарегистрирован')
                                            ->content(fn (?User $record): string => $record?->created_at?->format('d.m.Y H:i') ?? '—'),
                                        Placeholder::make('registration_channel_info')->label('Канал регистрации')
                                            ->content(fn (?User $record): string => $record?->registration_channel?->value ?? '—'),
                                        Placeholder::make('first_login_info')->label('Первый вход')
                                            ->content(fn (?User $record): string => $record?->first_logged_in_at?->format('d.m.Y H:i') ?? '—'),
                                        Placeholder::make('system_role_info')->label('Системная роль')
                                            ->content(fn (?User $record): string => $record?->system_role?->label() ?? '—'),
                                        Placeholder::make('identity_info')->label('Связанные идентификаторы')
                                            ->content(fn (?User $record): string => (string) count($record?->identityIds() ?? [])),
                                        Placeholder::make('telegram_info')->label('Telegram')
                                            ->content(fn (?User $record): string => $record?->telegramAccount ? 'Подключён' : 'Не подключён'),
                                        Placeholder::make('vk_info')->label('VK')
                                            ->content(fn (?User $record): string => $record?->vkAccount ? 'Подключён' : 'Не подключён'),
                                    ])->columns(2),
                                View::make('filament.backoffice.user-save-general'),
                            ]),
                        Tab::make('Профиль')
                            ->icon('heroicon-o-identification')
                            ->schema([
                                Section::make('Персональные данные')
                                    ->description('Изменения подтверждённых данных требуют основания. Ввод выполняется здесь, без отдельной формы в попапе.')
                                    ->schema([
                                        TextInput::make('profile_first_name')->label('Имя')->maxLength(100),
                                        TextInput::make('profile_last_name')->label('Фамилия')->maxLength(100),
                                        TextInput::make('profile_middle_name')->label('Отчество')->maxLength(100),
                                        DatePicker::make('profile_birth_date')->label('Дата рождения')->maxDate(now()),
                                        Select::make('profile_gender')->label('Пол')->placeholder('Не указан')
                                            ->options(collect(\App\Modules\Identity\Domain\Enums\UserGenderEnum::cases())->mapWithKeys(
                                                fn ($gender): array => [$gender->value => $gender->label()],
                                            )->all()),
                                    ])->columns(2),
                                View::make('filament.backoffice.user-save-profile'),
                            ]),
                        Tab::make('Роль')
                            ->icon('heroicon-o-users')
                            ->schema([
                                View::make('filament.backoffice.user-participation-roles'),
                            ]),
                        Tab::make('Роли и права')
                            ->icon('heroicon-o-shield-check')
                            ->schema([
                                Tabs::make('Уровень разрешений')
                                    ->persistTabInQueryString('permission-scope')
                                    ->tabs([
                                        Tab::make('Системные')
                                            ->schema([
                                                Section::make('Системная роль пользователя')
                                                    ->description('Смена роли подтверждается отдельно и может изменить доступные права. Служебные и старшие роли не назначаются.')
                                                    ->schema([
                                                        Select::make('requested_system_role')->label('Системная роль')
                                                            ->options(function (?User $record): array {
                                                                $actorRole = auth('web')->user()?->canonical()->system_role;
                                                                $options = [];
                                                                foreach (UserSystemRoleEnum::cases() as $role) {
                                                                    if ($actorRole !== null
                                                                        && ! in_array($role, [
                                                                            UserSystemRoleEnum::SUPERADMIN,
                                                                            UserSystemRoleEnum::SYSTEM,
                                                                            UserSystemRoleEnum::MODERATOR,
                                                                            UserSystemRoleEnum::EDITOR,
                                                                        ], true)
                                                                        && $role->numericValue() < $actorRole->numericValue()) {
                                                                        $options[$role->value] = $role->label();
                                                                    }
                                                                }
                                                                if ($record !== null && ! isset($options[$record->system_role->value])) {
                                                                    $options[$record->system_role->value] = $record->system_role->label().' (текущая)';
                                                                }
                                                                return $options;
                                                            })
                                                            ->disabled(fn (?User $record): bool => $record === null
                                                                || ! (auth('web')->user()?->can('manage-user-system-role', $record) ?? false))
                                                            ->native(false),
                                                        View::make('filament.backoffice.user-save-system-role'),
                                                    ]),
                                                Section::make('Специализации менеджера')
                                                    ->description('Специализации и должность редактируются здесь; изменения подтверждаются отдельно от системной роли.')
                                                    ->visible(fn (?User $record): bool => $record?->system_role === UserSystemRoleEnum::MANAGER)
                                                    ->schema([
                                                        TextInput::make('manager_staff_title')->label('Должность (не влияет на разрешения)')->maxLength(120)
                                                            ->disabled(fn (?User $record): bool => $record === null
                                                                || (auth('web')->user() === null || ! app(StaffSpecializationManager::class)->mayManage(auth('web')->user(), $record))),
                                                        CheckboxList::make('manager_specialization_ids')->label('Функциональные специализации')
                                                            ->options(fn (): array => ManagerSpecialization::query()->orderBy('name')->pluck('name', 'id')->all())
                                                            ->columns(2)
                                                            ->disabled(fn (?User $record): bool => $record === null
                                                                || ! app(StaffSpecializationManager::class)->mayManage(auth('web')->user(), $record)),
                                                        View::make('filament.backoffice.user-save-specializations'),
                                                    ]),
                                                Section::make('Глобальные права — создание')
                                                    ->schema([
                                                        CheckboxList::make('creation_permissions')->label('Индивидуальные назначения')
                                                            ->options(fn (?User $record): array => self::selectablePermissions($global->creationPermissions(), $record, false))
                                                            ->columns(2),
                                                        CheckboxList::make('locked_creation_permissions')->label('Недопустимые по роли или делегированию (просмотр)')
                                                            ->options(fn (?User $record): array => self::selectablePermissions($global->creationPermissions(), $record, true))
                                                            ->disabled()->dehydrated(false)->columns(2),
                                                    ]),
                                                Section::make('Системные полномочия и модерация')
                                                    ->description('Делегировать можно только права, имеющиеся у администратора и допускаемые ролью получателя.')
                                                    ->schema([
                                                        CheckboxList::make('privileged_permissions')->label('Индивидуальные назначения')
                                                            ->options(fn (?User $record): array => self::selectablePermissions($global->privilegedPermissions(), $record, false))
                                                            ->columns(2),
                                                        CheckboxList::make('locked_privileged_permissions')->label('Недопустимые по роли или делегированию (просмотр)')
                                                            ->options(fn (?User $record): array => self::selectablePermissions($global->privilegedPermissions(), $record, true))
                                                            ->disabled()->dehydrated(false)->columns(2),
                                                    ]),
                                                Section::make('Разрешения Filament')
                                                    ->schema([
                                                        CheckboxList::make('backoffice_permissions')->label('Индивидуальные назначения')
                                                            ->options(fn (?User $record): array => self::selectablePermissions(Permission::backofficeCases(), $record, false))
                                                            ->columns(2),
                                                        CheckboxList::make('locked_backoffice_permissions')->label('Недопустимые по роли или делегированию (просмотр)')
                                                            ->options(fn (?User $record): array => self::selectablePermissions(Permission::backofficeCases(), $record, true))
                                                            ->disabled()->dehydrated(false)->columns(2),
                                                    ]),
                                                Hidden::make('change_reason'),
                                                View::make('filament.backoffice.user-save-permissions'),
                                            ]),
                                        Tab::make('Контекстные')
                                            ->schema([
                                                View::make('filament.backoffice.user-venue-context')
                                                    ->viewData(['editedUser' => $record]),
                                            ]),
                                    ]),
                            ]),
                        Tab::make('История изменений')
                            ->icon('heroicon-o-clock')
                            ->schema([
                                View::make('filament.backoffice.user-personal-audit')
                                    ->viewData(['editedUser' => $record]),
                            ]),
                    ];
                })
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('username')->label('Пользователь')->searchable()->sortable()->weight('bold'),
            TextColumn::make('system_role')->label('Системная роль')
                ->badge()->formatStateUsing(fn ($record): string => $record->system_role->label()),
            TextColumn::make('staff_title')->label('Должность')->placeholder('—'),
            TextColumn::make('status')->label('Статус')
                ->badge()->formatStateUsing(fn ($record): string => $record->status->label()),
            TextColumn::make('updated_at')->label('Изменён')->dateTime('d.m.Y H:i')->sortable(),
        ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                EditAction::make()->label('Редактировать')->visible(fn (User $record): bool => self::canEdit($record)),
                Action::make('editDenied')
                    ->label('Редактировать')
                    ->icon('heroicon-o-lock-closed')
                    ->color('gray')
                    ->visible(fn (User $record): bool => ! self::canEdit($record))
                    ->modalHeading('Нет доступа к редактированию')
                    ->modalDescription('У вас нет права для редактирования пользователя.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Закрыть')
                    ->action(static function (): void {}),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBackofficeUsers::route('/'),
            'edit' => EditBackofficeUser::route('/{record}/edit'),
            'role' => EditBackofficeUserRole::route('/{record}/roles/{participationRole}'),
        ];
    }
}
