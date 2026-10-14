<?php

namespace App\Filament\Backoffice\Resources\BackofficeUsers;

use App\Filament\Backoffice\Resources\BackofficeUsers\Pages\EditBackofficeUser;
use App\Filament\Backoffice\Resources\BackofficeUsers\Pages\ListBackofficeUsers;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
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

    public static function getNavigationLabel(): string { return 'Доступ к Filament'; }
    public static function getModelLabel(): string { return 'доступ пользователя'; }
    public static function getPluralModelLabel(): string { return 'доступы пользователей'; }

    private static function permissions(): BackofficePermissionManager
    {
        return app(BackofficePermissionManager::class);
    }

    public static function canViewAny(): bool
    {
        $actor = auth('web')->user();

        return $actor !== null && self::permissions()->mayManage($actor);
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof User && self::canEdit($record);
    }

    public static function canEdit(Model $record): bool
    {
        $actor = auth('web')->user();

        return $actor !== null && $record instanceof User
            && self::permissions()->mayManage($actor, $record);
    }

    public static function canCreate(): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereNull('canonical_user_id')
            ->whereNotIn('system_role', [
                UserSystemRoleEnum::SUPERADMIN->value,
                UserSystemRoleEnum::SYSTEM->value,
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        $options = [];
        foreach (Permission::backofficeCases() as $permission) {
            $options[$permission->value] = $permission->label();
        }

        return $schema->components([
            Section::make('Административный доступ')
                ->description('Права индивидуальны и не меняют системную роль. Для доступа требуется включить «Вход в Filament» и нужные права на раздел. Отключение входа немедленно блокирует всю панель, в том числе открытые вкладки при следующем запросе.')
                ->schema([
                    CheckboxList::make('backoffice_permissions')
                        ->label('Разрешения Filament')->options($options)
                        ->columns(1)->bulkToggleable()
                        ->helperText('Удаление и CSS-стили выдаются отдельно. Исполняемый HTML/JS виджет остаётся только для superadmin.'),
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
