<?php

namespace App\Filament\Backoffice\Resources\Teams\RelationManagers;

use App\Filament\Backoffice\Resources\Teams\BackofficeTeamMembers;
use App\Filament\Backoffice\Resources\Teams\TeamResource;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'memberships';
    protected static ?string $title = 'Участники и роли';
    protected static string|\BackedEnum|null $icon = 'heroicon-o-users';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return TeamResource::canView($ownerRecord) && ! $ownerRecord->trashed();
    }

    public function form(Schema $schema): Schema { return $schema->components([]); }

    private static function levels(bool $includeOwner = false): array
    {
        return collect(TeamMembershipAccessLevelEnum::cases())
            ->filter(fn ($role): bool => $includeOwner || $role !== TeamMembershipAccessLevelEnum::OWNER)
            ->mapWithKeys(fn ($role): array => [$role->value => $role->label()])->all();
    }

    private static function roles(): array
    {
        return collect(TeamMemberTypeEnum::cases())->mapWithKeys(
            fn ($role): array => [$role->value => $role->label()],
        )->all();
    }

    private function roleFields(bool $includeOwner = false): array
    {
        return [
            Select::make('access_level')->label('Уровень доступа в команде')
                ->options(self::levels($includeOwner))->required()->native(false)
                ->helperText('Спортивная роль «Менеджер» выбирается ниже. Уровень доступа «Ответственный» даёт дополнительные полномочия.'),
            CheckboxList::make('sport_roles')->label('Спортивные роли')
                ->options(self::roles())->columns(3)->required()->minItems(1)
                ->helperText('Участник может одновременно быть игроком, тренером и менеджером.'),
            Toggle::make('is_captain')->label('Капитан команды')->default(false)
                ->helperText('В команде может быть только один капитан; новый заменяет предыдущего.'),
            TextInput::make('jersey_number')->label('Игровой номер')
                ->integer()->minValue(0)->maxValue(999)->nullable(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
                ->whereHas('contract', fn ($q) => $q->where('status', ContractStatusEnum::ACTIVE->value)))
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('user.username')->label('Пользователь')->searchable()->sortable(),
                TextColumn::make('access_level')->label('Уровень доступа')->badge()
                    ->formatStateUsing(fn (string $state): string =>
                        TeamMembershipAccessLevelEnum::tryFrom($state)?->label() ?? $state),
                TextColumn::make('sport_roles_label')->label('Спортивные роли')
                    ->state(fn (ContractMembership $record): string => implode(', ', array_map(
                        fn (string $role): string => TeamMemberTypeEnum::tryFrom($role)?->label() ?? $role,
                        $record->sportRoleValues(),
                    )) ?: '—'),
                IconColumn::make('is_captain')->label('Капитан')->boolean(),
                TextColumn::make('jersey_number')->label('№')->placeholder('—'),
            ])
            ->headerActions([
                Action::make('addMember')->label('Добавить участника')
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn (): bool => TeamResource::canEdit($this->getOwnerRecord()))
                    ->schema([
                        Select::make('user_id')->label('Пользователь')->required()
                            ->searchable()->searchDebounce(300)
                            ->getSearchResultsUsing(fn (string $search): array => User::query()
                                ->whereNull('canonical_user_id')->whereLike('username', '%'.$search.'%')
                                ->limit(30)->pluck('username', 'id')->all())
                            ->getOptionLabelUsing(fn ($value): ?string => User::query()
                                ->whereKey($value)->value('username')),
                        ...$this->roleFields(),
                    ])
                    ->action(function (array $data): void {
                        app(BackofficeTeamMembers::class)->add($this->getOwnerRecord(), $data);
                        Notification::make()->success()->title('Участник добавлен')->send();
                    }),
            ])
            ->recordActions([
                Action::make('editMember')->label('Редактировать')->icon('heroicon-o-pencil-square')
                    ->visible(fn (): bool => TeamResource::canEdit($this->getOwnerRecord()))
                    ->fillForm(fn (ContractMembership $record): array => [
                        'access_level' => $record->access_level,
                        'sport_roles' => $record->sportRoleValues(),
                        'is_captain' => (bool) $record->is_captain,
                        'jersey_number' => $record->jersey_number,
                    ])
                    ->schema($this->roleFields(includeOwner: true))
                    ->action(function (ContractMembership $record, array $data): void {
                        app(BackofficeTeamMembers::class)->update($this->getOwnerRecord(), $record, $data);
                        Notification::make()->success()->title('Роли участника обновлены')->send();
                    }),
                Action::make('removeMember')->label('Исключить')->icon('heroicon-o-user-minus')
                    ->color('danger')->requiresConfirmation()
                    ->modalDescription('Контракт участника станет неактивным. История сыгранных игр сохранится.')
                    ->visible(fn (ContractMembership $record): bool =>
                        TeamResource::canEdit($this->getOwnerRecord()) && $record->access_level !== 'owner')
                    ->action(function (ContractMembership $record): void {
                        app(BackofficeTeamMembers::class)->remove($this->getOwnerRecord(), $record);
                        Notification::make()->success()->title('Участник исключён')->send();
                    }),
            ]);
    }
}
