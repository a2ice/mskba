<?php

namespace App\Filament\Backoffice\Resources\Venues\RelationManagers;

use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum as Role;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\UseCases\ManageVenueMembershipHandler;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class CommercialMembershipsRelationManager extends RelationManager
{
    protected static string $relationship = 'commercialMemberships';
    protected static ?string $title = 'Коммерческие роли и права';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth('web')->user();

        return VenueResource::authorized()
            && config('features.venue_rental.rental_flow', false)
            && ! $ownerRecord->trashed()
            && $user !== null && $user->isConfirmed()
            && $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN);
    }

    /** The same domain handler as the public venue account, no separate ACL logic. */
    private function manager(): ManageVenueMembershipHandler
    {
        return app(ManageVenueMembershipHandler::class);
    }

    private function actor(): User
    {
        $user = auth('web')->user();
        abort_unless($user && $user->isConfirmed()
            && $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN), 403);

        return $user;
    }

    /** @return array<string,string> */
    private function roleOptions(): array
    {
        return collect([Role::MANAGER, Role::BOOKING_OPERATOR, Role::FINANCE_VIEWER])
            ->mapWithKeys(fn (Role $role) => [$role->value => $role->label()])->all();
    }

    /** @return array<int,mixed> */
    private function roleFields(): array
    {
        return [
            Select::make('role')->label('Коммерческая роль')->options($this->roleOptions())
                ->default(Role::MANAGER->value)->required()->live(),
            CheckboxList::make('permissions')->label('Полномочия')
                ->options(function (Get $get): array {
                    $role = Role::tryFrom((string) $get('role')) ?? Role::MANAGER;

                    return collect($role->allowedPermissions())->mapWithKeys(
                        fn (VenuePermissionEnum $value): array => [$value->value => $value->label()]
                    )->all();
                })
                ->default([
                    VenuePermissionEnum::VIEW->value,
                    VenuePermissionEnum::VIEW_BOOKING_REQUESTS->value,
                    VenuePermissionEnum::CONFIRM_BOOKING_REQUESTS->value,
                    VenuePermissionEnum::REJECT_BOOKING_REQUESTS->value,
                    VenuePermissionEnum::VIEW_PAYMENTS->value,
                    VenuePermissionEnum::CONFIRM_PAYMENTS->value,
                ])->columns(2)
                ->helperText('Для агента с оплатами используйте роль «Менеджер»: оператор не может подтвердить получение оплаты. Ненужные права снимите.'),
        ];
    }

    /** @return array<VenuePermissionEnum> */
    private function permissions(array $data): array
    {
        return collect($data['permissions'] ?? [])->map(
            fn (string $value): VenuePermissionEnum => VenuePermissionEnum::from($value)
        )->all();
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('user.username')->label('Пользователь')->searchable(),
            TextColumn::make('access_level')->label('Роль')
                ->formatStateUsing(fn (string $state): string => Role::tryFrom($state)?->label() ?? $state),
            TextColumn::make('contract.permissions.permission')->label('Права')
                ->formatStateUsing(fn ($state): string => is_string($state) ? $state : '')
                ->listWithLineBreaks(),
            TextColumn::make('contract.expires_at')->label('Действует до')
                ->dateTime('d.m.Y H:i')->placeholder('Бессрочно'),
        ])
        ->modifyQueryUsing(fn ($query) => $query
            ->whereHas('contract', fn ($contract) => $contract
                ->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->with(['user', 'contract.permissions']))
        ->headerActions([
            Action::make('grantCommercialRole')->label('Назначить участника')
                ->icon('heroicon-o-user-plus')
                ->modalHeading('Коммерческие права на площадку')
                ->modalDescription('Назначение агентской комиссии не выдаёт автоматически полномочия на заявки или оплату.')
                ->schema([
                    Select::make('user_id')->label('Подтверждённый пользователь')
                        ->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search): array =>
                            User::query()->where('status', UserStatusEnum::CONFIRMED->value)
                                ->whereNull('deleted_at')
                                ->where('username', 'like', '%'.$search.'%')
                                ->orderBy('username')->limit(25)->pluck('username', 'id')->all())
                        ->getOptionLabelUsing(fn ($value): ?string => User::query()->find($value)?->username),
                    ...$this->roleFields(),
                ])
                ->action(function (array $data): void {
                    $this->manager()->grant(
                        $this->getOwnerRecord(),
                        User::query()->findOrFail((int) $data['user_id']),
                        Role::from($data['role']),
                        $this->actor(),
                        $this->permissions($data),
                    );
                    Notification::make()->success()->title('Коммерческая роль назначена')->send();
                }),
        ])
        ->recordActions([
            Action::make('editCommercialRole')->label('Изменить права')
                ->icon('heroicon-o-pencil-square')
                ->visible(fn (ContractMembership $record): bool => $record->access_level !== Role::OWNER->value)
                ->schema($this->roleFields())
                ->fillForm(fn (ContractMembership $record): array => [
                    'role' => $record->access_level,
                    'permissions' => $record->contract?->permissions
                        ->pluck('permission')->all() ?? [],
                ])
                ->action(function (ContractMembership $record, array $data): void {
                    $this->manager()->change(
                        $this->getOwnerRecord(), $record, Role::from($data['role']),
                        $this->actor(), $this->permissions($data),
                    );
                    Notification::make()->success()->title('Полномочия обновлены')->send();
                }),
            Action::make('revokeCommercialRole')->label('Отозвать')
                ->icon('heroicon-o-x-circle')->color('danger')->requiresConfirmation()
                ->visible(fn (ContractMembership $record): bool => $record->access_level !== Role::OWNER->value)
                ->action(function (ContractMembership $record): void {
                    $this->manager()->revoke($this->getOwnerRecord(), $record, $this->actor());
                    Notification::make()->success()->title('Роль отозвана')->send();
                }),
        ])
        ->defaultSort('id', 'desc');
    }
}
