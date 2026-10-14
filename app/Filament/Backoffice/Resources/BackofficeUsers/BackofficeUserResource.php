<?php

namespace App\Filament\Backoffice\Resources\BackofficeUsers;

use App\Filament\Backoffice\Resources\BackofficeUsers\Pages\EditBackofficeUser;
use App\Filament\Backoffice\Resources\BackofficeUsers\Pages\ListBackofficeUsers;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Application\Services\GlobalOperationalPermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class BackofficeUserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static ?string $recordTitleAttribute = 'username';
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shield-check';
    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string { return 'Пользователи и права'; }
    public static function getModelLabel(): string { return 'права пользователя'; }
    public static function getPluralModelLabel(): string { return 'права пользователей'; }

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
            && app(GlobalOperationalPermissionManager::class)->mayManage($actor, $record);
    }

    public static function canCreate(): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }

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
                UserSystemRoleEnum::MODERATOR->value,
                UserSystemRoleEnum::EDITOR->value,
                UserSystemRoleEnum::USER->value,
            ]);
        }

        return $query->whereRaw('1 = 0');
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
            Section::make('Глобальные права — создание')
                ->description('Действуют на уровне портала. Значения по умолчанию и индивидуальные запреты сохраняются. Администратор вправе делегировать эти некритичные права только пользователю с меньшей системной ролью.')
                ->schema([
                    CheckboxList::make('creation_permissions')
                        ->label('Создание объектов')
                        ->options($optionMap($global->creationPermissions()))
                        ->columns(2)->bulkToggleable(),
                ]),
            Section::make('Глобальные права — администрирование и техподдержка')
                ->description('Критичные права. Назначать и отзывать может только superadmin. Право на проверку изменения подтверждённых данных доступно исключительно пользователю с ролью admin.')
                ->visible(fn (): bool => auth('web')->user()?->canonical()->system_role === UserSystemRoleEnum::SUPERADMIN)
                ->schema([
                    CheckboxList::make('privileged_permissions')
                        ->label('Критичные разрешения')
                        ->options(fn (?User $record): array => $optionMap(
                            array_values(array_filter($global->privilegedPermissions(),
                                fn (Permission $permission): bool => $permission !== Permission::REVIEW_PROFILE_CHANGES
                                    || $record?->system_role?->atLeast(UserSystemRoleEnum::ADMIN),
                            ))
                        ))
                        ->columns(1)->bulkToggleable(),
                ]),
            Section::make('Filament — административный интерфейс')
                ->description('Вход в Filament и права на его ресурсы. Только superadmin может назначать эти разрешения. Доступ к исполняемым HTML/CSS/JS-виджетам по-прежнему только у superadmin.')
                ->visible(fn (): bool => auth('web')->user()?->canonical()->system_role === UserSystemRoleEnum::SUPERADMIN)
                ->schema([
                    CheckboxList::make('backoffice_permissions')
                        ->label('Разрешения Filament')
                        ->options($optionMap(Permission::backofficeCases()))
                        ->columns(2)->bulkToggleable(),
                ]),
            Section::make('Права внутри сущностей')
                ->description('Команды, площадки, мероприятия, турниры и секции: доступ назначается участникам или сотрудникам в конкретной сущности. Полный набор принадлежит владельцу/создателю/администратору самой сущности и superadmin. Глобальные флажки выше не предоставляют управление всеми чужими объектами.')
                ->schema([
                    Placeholder::make('contextual_scope')
                        ->label('Отдельный контекст доступа')
                        ->content('Настройка разрешений команды, площадки или мероприятия остаётся привязана к соответствующей сущности. Подключение их просмотра здесь — следующий этап, без выдачи глобальных объектных прав.'),
                ]),
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
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('username')->label('Пользователь')->searchable()->sortable()->weight('bold'),
            TextColumn::make('system_role')->label('Системная роль')
                ->badge()->formatStateUsing(fn ($record): string => $record->system_role->label()),
            TextColumn::make('status')->label('Статус')
                ->badge()->formatStateUsing(fn ($record): string => $record->status->label()),
            TextColumn::make('updated_at')->label('Изменён')->dateTime('d.m.Y H:i')->sortable(),
        ])
            ->defaultSort('id', 'desc')
            ->recordActions([EditAction::make()->label('Права')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBackofficeUsers::route('/'),
            'edit' => EditBackofficeUser::route('/{record}/edit'),
        ];
    }
}
