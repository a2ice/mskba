<?php

namespace App\Filament\Backoffice\Resources\ManagerSpecializations;

use App\Filament\Backoffice\Resources\ManagerSpecializations\Pages\CreateManagerSpecialization;
use App\Filament\Backoffice\Resources\ManagerSpecializations\Pages\EditManagerSpecialization;
use App\Filament\Backoffice\Resources\ManagerSpecializations\Pages\ListManagerSpecializations;
use App\Modules\Identity\Application\Services\StaffPermissionProfiles;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Models\ManagerSpecialization;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class ManagerSpecializationResource extends Resource
{
    protected static ?string $model = ManagerSpecialization::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-identification';
    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string { return 'Специализации'; }
    public static function getModelLabel(): string { return 'специализация'; }
    public static function getPluralModelLabel(): string { return 'специализации'; }

    private static function superadmin(): bool
    {
        $user = auth('web')->user();
        return $user !== null && $user->canonical()->isConfirmed()
            && ! $user->canonical()->isBlocked()
            && $user->canonical()->system_role === Role::SUPERADMIN;
    }

    public static function canViewAny(): bool { return self::superadmin(); }
    public static function canCreate(): bool { return self::superadmin(); }
    public static function canEdit(Model $record): bool { return self::superadmin(); }
    public static function canDelete(Model $record): bool
    {
        return self::superadmin() && $record instanceof ManagerSpecialization && ! $record->is_system;
    }
    public static function canDeleteAny(): bool { return false; }

    public static function form(Schema $schema): Schema
    {
        $profiles = app(StaffPermissionProfiles::class);
        return $schema->components([
            Section::make('Профиль специализации')
                ->description('Несколько специализаций суммируют разрешения. Права в профиле не могут быть индивидуально отозваны. Изменять профиль может только superadmin.')
                ->schema([
                    TextInput::make('name')->label('Название')->required()->maxLength(120),
                    TextInput::make('code')->label('Код')
                        ->required()->maxLength(60)->regex('/\A[a-z][a-z0-9_-]{1,59}\z/D')
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (?ManagerSpecialization $record): bool => $record !== null)
                        ->dehydrated(),
                    CheckboxList::make('permissions')->label('Предустановленные разрешения')
                        ->options(array_combine(
                            array_map(static fn (Permission $p): string => $p->value, Permission::cases()),
                            array_map(static fn (Permission $p): string => $p->label(), Permission::cases()),
                        ))
                        ->disableOptionWhen(fn (string $value): bool => ! $profiles->allowedInProfile(Role::MANAGER, Permission::from($value)))
                        ->columns(2)->columnSpanFull(),
                ]),
        ]);
    }

    public static function validatePermissions(array $data): void
    {
        abort_unless(self::superadmin(), 403);
        $permissions = $data['permissions'] ?? [];
        if (! is_array($permissions) || count($permissions) !== count(array_unique($permissions))) {
            throw ValidationException::withMessages(['data.permissions' => 'Некорректный список прав.']);
        }
        foreach ($permissions as $key) {
            $permission = is_string($key) ? Permission::tryFrom($key) : null;
            if ($permission === null || ! app(StaffPermissionProfiles::class)->allowedInProfile(Role::MANAGER, $permission)) {
                throw ValidationException::withMessages(['data.permissions' => 'В профиле менеджера присутствуют недопустимые права.']);
            }
        }
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Специализация')->searchable(),
            TextColumn::make('code')->label('Код'),
            TextColumn::make('permissions')->label('Разрешений')->state(fn (ManagerSpecialization $record): int => count((array) $record->permissions)),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListManagerSpecializations::route('/'),
            'create' => CreateManagerSpecialization::route('/create'),
            'edit' => EditManagerSpecialization::route('/{record}/edit')];
    }
}