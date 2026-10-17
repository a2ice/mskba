<?php

namespace App\Filament\Backoffice\Resources\SystemRoleProfiles;

use App\Filament\Backoffice\Resources\SystemRoleProfiles\Pages\EditSystemRoleProfile;
use App\Filament\Backoffice\Resources\SystemRoleProfiles\Pages\ListSystemRoleProfiles;
use App\Modules\Identity\Application\Services\StaffPermissionProfiles;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Models\SystemRolePermissionProfile;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class SystemRoleProfileResource extends Resource
{
    protected static ?string $model = SystemRolePermissionProfile::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';
    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string { return 'Профили системных ролей'; }
    public static function getModelLabel(): string { return 'профиль роли'; }
    public static function getPluralModelLabel(): string { return 'профили системных ролей'; }

    private static function superadmin(): bool
    {
        $actor = auth('web')->user()?->canonical();
        return $actor !== null && $actor->isConfirmed() && ! $actor->isBlocked()
            && $actor->system_role === Role::SUPERADMIN;
    }
    public static function canViewAny(): bool { return self::superadmin(); }
    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool
    {
        return self::superadmin() && $record instanceof SystemRolePermissionProfile
            && in_array($record->role, ['admin', 'manager', 'user'], true);
    }
    public static function canDelete(Model $record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }

    public static function form(Schema $schema): Schema
    {
        $profiles = app(StaffPermissionProfiles::class);
        return $schema->components([
            Section::make('Базовые права системной роли')
                ->description('Применяются ко всем пользователям роли и не могут быть сняты индивидуально. Superadmin всегда имеет полный доступ.')
                ->schema([
                    CheckboxList::make('permissions')->label('Разрешения')
                        ->options(array_combine(
                            array_map(static fn (Permission $p): string => $p->value, Permission::cases()),
                            array_map(static fn (Permission $p): string => $p->label(), Permission::cases())
                        ))
                        ->disableOptionWhen(fn (string $value, ?SystemRolePermissionProfile $record): bool =>
                            $record === null || ! $profiles->allowedInProfile(Role::from($record->role), Permission::from($value)))
                        ->columns(2),
                ]),
        ]);
    }

    public static function validatePermissions(SystemRolePermissionProfile $record, array $data): void
    {
        abort_unless(self::canEdit($record), 403);
        $keys = $data['permissions'] ?? null;
        if (! is_array($keys) || count($keys) !== count(array_unique($keys))) {
            throw ValidationException::withMessages(['data.permissions' => 'Некорректный набор прав.']);
        }

        $role = Role::from($record->role);
        foreach ($keys as $key) {
            $permission = is_string($key) ? Permission::tryFrom($key) : null;
            if ($permission === null || ! app(StaffPermissionProfiles::class)->allowedInProfile($role, $permission)) {
                throw ValidationException::withMessages(['data.permissions' => 'Недопустимое системное разрешение.']);
            }
        }
        if (in_array($role, [Role::ADMIN, Role::MANAGER], true)
            && ! in_array(Permission::BACKOFFICE_ACCESS->value, $keys, true)) {
            throw ValidationException::withMessages(['data.permissions' => 'Вход в Filament обязателен для этой системной роли.']);
        }
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('role')->label('Роль')->formatStateUsing(fn (string $state): string => Role::from($state)->label()),
            TextColumn::make('permissions')->label('Базовых разрешений')
                ->state(fn (SystemRolePermissionProfile $record): int => count((array) $record->permissions)),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSystemRoleProfiles::route('/'),
            'edit' => EditSystemRoleProfile::route('/{record}/edit')];
    }
}