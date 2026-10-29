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
use App\Modules\Identity\Domain\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
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
            || $profiles->inherited($record, $permission)
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
        $optionMap = static function (array $permissions): array {
            $options = [];
            foreach ($permissions as $permission) {
                $options[$permission->value] = $permission->label();
            }

            return $options;
        };

        return $schema->components([
            Tabs::make('Управление пользователем')
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make('Системная роль')
                        ->icon('heroicon-o-user-circle')
                        ->schema([
                            Section::make('Системная роль пользователя')
                                ->description('Роль определяет уровень системных полномочий. Для назначения или снятия роли используйте действие «Изменить системную роль» вверху страницы. Разрешены только роли ниже вашей.')
                                ->schema([
                                    Placeholder::make('current_system_role')
                                        ->label('Текущая системная роль')
                                        ->content(fn (?User $record): string => $record?->system_role->label() ?? '—'),
                                ]),
                        ]),
                    Tab::make('Специализации')
                        ->icon('heroicon-o-identification')
                        ->schema([
                            Section::make('Специализации менеджера')
                                ->description('Назначаются одновременно; изменение общего профиля действует на всех менеджеров с этой специализацией.')
                                ->schema([
                                    Placeholder::make('manager_specialization_summary')
                                        ->label('Активные специализации')
                                        ->content(fn (?User $record): string => $record?->system_role === UserSystemRoleEnum::MANAGER
                                            ? ($record->managerSpecializations()->pluck('name')->implode(' · ') ?: 'Не назначены')
                                            : 'Специализации доступны только системной роли Manager.'),
                                ]),
                        ]),
                    Tab::make('Глобальные права')
                        ->icon('heroicon-o-plus-circle')
                        ->schema([
                            Section::make('Глобальные права — создание')
                                ->description('Действуют на уровне портала. Базовые права роли и специализаций не снимаются индивидуально. Недоступные разрешения показаны неактивными.')
                                ->schema([
                                    CheckboxList::make('creation_permissions')
                                        ->label('Создание объектов — индивидуальные назначения')
                                        ->options(fn (?User $record): array => self::selectablePermissions($global->creationPermissions(), $record, false))
                                        ->columns(2)->bulkToggleable(),
                                    CheckboxList::make('locked_creation_permissions')
                                        ->label('Наследуемые или недоступные права (только просмотр)')
                                        ->options(fn (?User $record): array => self::selectablePermissions($global->creationPermissions(), $record, true))
                                        ->disabled()->dehydrated(false)
                                        ->columns(2),
                                ]),
                        ]),
                    Tab::make('Администрирование')
                        ->icon('heroicon-o-shield-check')
                        ->schema([
                            Section::make('Глобальные права — администрирование и техподдержка')
                                ->description('Назначение разрешено только в пределах собственных полномочий и системной роли получателя; недоступные права остаются видимы.')
                                ->visible(fn (): bool => auth('web')->user()?->canonical()->system_role?->atLeast(UserSystemRoleEnum::ADMIN) ?? false)
                                ->schema([
                                    CheckboxList::make('privileged_permissions')
                                        ->label('Критичные разрешения — индивидуальные назначения')
                                        ->options(fn (?User $record): array => self::selectablePermissions($global->privilegedPermissions(), $record, false))
                                        ->columns(1)->bulkToggleable(),
                                    CheckboxList::make('locked_privileged_permissions')
                                        ->label('Наследуемые или недоступные права (только просмотр)')
                                        ->options(fn (?User $record): array => self::selectablePermissions($global->privilegedPermissions(), $record, true))
                                        ->disabled()->dehydrated(false)
                                        ->columns(1),
                                ]),
                        ]),
                    Tab::make('Filament')
                        ->icon('heroicon-o-cog-6-tooth')
                        ->schema([
                            Section::make('Filament — административный интерфейс')
                                ->description('Вход и ресурсы Filament. Администратор может передать только имеющиеся у него разрешения. Базовые права роли и специализаций неотзываемы.')
                                ->visible(fn (): bool => auth('web')->user()?->canonical()->system_role?->atLeast(UserSystemRoleEnum::ADMIN) ?? false)
                                ->schema([
                                    CheckboxList::make('backoffice_permissions')
                                        ->label('Разрешения Filament — индивидуальные назначения')
                                        ->options(fn (?User $record): array => self::selectablePermissions(Permission::backofficeCases(), $record, false))
                                        ->columns(2)->bulkToggleable(),
                                    CheckboxList::make('locked_backoffice_permissions')
                                        ->label('Наследуемые или недоступные права (только просмотр)')
                                        ->options(fn (?User $record): array => self::selectablePermissions(Permission::backofficeCases(), $record, true))
                                        ->disabled()->dehydrated(false)
                                        ->columns(2),
                                ]),
                        ]),
                    Tab::make('Права сущностей')
                        ->icon('heroicon-o-building-office-2')
                        ->schema([
                            Section::make('Права внутри сущностей')
                                ->description('Команды, площадки, мероприятия, турниры и секции: доступ назначается участникам или сотрудникам в конкретной сущности. Полный набор принадлежит владельцу/создателю/администратору самой сущности и superadmin. Глобальные флажки выше не предоставляют управление всеми чужими объектами.')
                                ->schema([
                                    Placeholder::make('contextual_scope')
                                        ->label('Отдельный контекст доступа')
                                        ->content('Настройка разрешений команды, площадки или мероприятия остаётся привязана к соответствующей сущности. Подключение их просмотра здесь — следующий этап, без выдачи глобальных объектных прав.'),
                                ]),
                        ]),
                    Tab::make('Источники прав')
                        ->icon('heroicon-o-information-circle')
                        ->schema([
                            Section::make('Источники глобальных прав')
                                ->description('Показывает, почему действует значение: правило системной роли, индивидуальная выдача или явный запрет.')
                                ->collapsed()
                                ->schema([
                                    Placeholder::make('global_sources')
                                        ->label('Текущие назначения')
                                        ->content(function (?User $record) use ($global): string {
                                            if ($record === null) {
                                                return 'Выберите пользователя.';
                                            }
                                            $sources = $global->sources($record);

                                            return implode(' | ', array_map(
                                                static fn (Permission $p): string => $p->label().': '.($sources[$p->value] ?? '—'),
                                                array_merge($global->creationPermissions(), $global->privilegedPermissions()),
                                            ));
                                        }),
                                ]),
                        ]),
                ])
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
