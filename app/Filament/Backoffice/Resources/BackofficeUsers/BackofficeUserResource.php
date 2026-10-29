<?php

namespace App\Filament\Backoffice\Resources\BackofficeUsers;

use App\Filament\Backoffice\Resources\BackofficeUsers\Pages\EditBackofficeUser;
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
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
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
                    $roleTabs = [];
                    if ($record !== null) {
                        foreach ($record->participationRoles()->where('status', UserParticipationRoleStatusEnum::ACTIVE)->orderBy('id')->get() as $participationRole) {
                            $role = $participationRole->role;
                            $roleDetails = [];
                            if ($role === UserParticipationRoleEnum::PLAYER) {
                                $roleDetails[] = Section::make('Анкета игрока')
                                    ->description('Редактировать параметры можно через отдельное подтверждаемое действие «Изменить профиль игрока».')
                                    ->schema([
                                        Placeholder::make('player_height')->label('Рост')
                                            ->content(fn (?User $record): string => $record?->playerProfile?->height_cm !== null
                                                ? $record->playerProfile->height_cm.' см' : '—'),
                                        Placeholder::make('player_weight')->label('Вес')
                                            ->content(fn (?User $record): string => $record?->playerProfile?->weight_kg !== null
                                                ? $record->playerProfile->weight_kg.' кг' : '—'),
                                        Placeholder::make('player_body_type')->label('Телосложение')
                                            ->content(fn (?User $record): string => $record?->playerProfile?->body_type?->label() ?? '—'),
                                        Placeholder::make('player_experience')->label('Занимается с')
                                            ->content(fn (?User $record): string => (string) ($record?->playerProfile?->experience_started_year ?? '—')),
                                        Placeholder::make('player_comment')->label('Комментарий')
                                            ->content(fn (?User $record): string => $record?->playerProfile?->comment ?: '—'),
                                    ])->columns(2);
                            }
                            $roleTabs[] = Tab::make('Роль '.$role->label())
                                ->schema([
                                    Section::make($role->label().' — параметры участия')
                                        ->description('Данные роли отображаются отдельно от системных прав.')
                                        ->schema([
                                            Placeholder::make('participant_role_'.$role->value)
                                                ->label('Состояние')
                                                ->content($participationRole->status->value),
                                            Placeholder::make('participant_role_since_'.$role->value)
                                                ->label('Назначена')
                                                ->content($participationRole->assigned_at?->format('d.m.Y H:i') ?? '—'),
                                            Placeholder::make('participant_role_comment_'.$role->value)
                                                ->label('Комментарий')
                                                ->content($participationRole->comment ?: '—'),
                                        ]),
                                    ...$roleDetails,
                                    View::make('filament.backoffice.user-participation-role-edit')
                                        ->viewData(['participationRoleId' => $participationRole->id]),
                                ]);
                        }
                    }

                    return [
                        Tab::make('Общее')
                            ->icon('heroicon-o-information-circle')
                            ->schema([
                                View::make('filament.backoffice.user-general-overview')
                                    ->viewData(['editedUser' => $record]),
                            ]),
                        Tab::make('Профиль')
                            ->icon('heroicon-o-identification')
                            ->schema([
                                Section::make('Персональные данные')
                                    ->description('Для изменения подтверждённых значений используй действие «Изменить личные данные». Каждое изменение требует основания, подтверждения и записывается в журнал.')
                                    ->schema([
                                        Placeholder::make('profile_name')->label('Имя')
                                            ->content(fn (?User $record): string => $record?->profile?->first_name ?? '—'),
                                        Placeholder::make('profile_last_name')->label('Фамилия')
                                            ->content(fn (?User $record): string => $record?->profile?->last_name ?? '—'),
                                        Placeholder::make('profile_middle_name')->label('Отчество')
                                            ->content(fn (?User $record): string => $record?->profile?->middle_name ?? '—'),
                                        Placeholder::make('profile_birth')->label('Дата рождения')
                                            ->content(fn (?User $record): string => $record?->profile?->birth_date?->format('d.m.Y') ?? '—'),
                                        Placeholder::make('profile_gender')->label('Пол')
                                            ->content(fn (?User $record): string => $record?->profile?->gender?->label() ?? 'Не указан'),
                                    ])->columns(2),
                            ]),
                        ...$roleTabs,
                        Tab::make('Роли и права')
                            ->icon('heroicon-o-shield-check')
                            ->schema([
                                Tabs::make('Уровень разрешений')
                                    ->persistTabInQueryString('permission-scope')
                                    ->tabs([
                                        Tab::make('Системные')
                                            ->schema([
                                                View::make('filament.backoffice.user-system-roles')
                                                    ->viewData(['editedUser' => $record]),
                                                Section::make('Специализации менеджера')
                                                    ->description('Меняются отдельным действием вверху страницы; не подменяют системные роли.')
                                                    ->schema([
                                                        Placeholder::make('manager_specialization_summary')->label('Активные специализации')
                                                            ->content(fn (?User $record): string => $record?->system_role === UserSystemRoleEnum::MANAGER
                                                                ? ($record->managerSpecializations()->pluck('name')->implode(' · ') ?: 'Не назначены')
                                                                : 'Доступны только менеджеру.'),
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
                                                Section::make('Подтверждение изменений прав')
                                                    ->description('Все индивидуальные назначения сохраняются отдельной операцией. Основание попадёт в журнал; перед записью появится подтверждение.')
                                                    ->schema([
                                                        Textarea::make('change_reason')->label('Основание изменения прав')
                                                            ->required()->minLength(10)->maxLength(500)
                                                            ->dehydrated(),
                                                    ]),
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
        ];
    }
}
