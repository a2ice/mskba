<?php

namespace App\Filament\Backoffice\Resources\Venues\RelationManagers;

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Venue\Application\Services\VenueContractInventory;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\Select;
use Filament\Tables\Table;

final class ContractHistoryRelationManager extends VenueContractsRelationManager
{
    protected static string $relationship = 'memberships';
    protected static ?string $title = 'Все контракты';

    public function table(Table $table): Table
    {
        return $table->query(fn () => app(VenueContractInventory::class)->memberships($this->getOwnerRecord(), $this->actor()))
            ->columns([
                TextColumn::make('contract_id')->label('№')->sortable(),
                TextColumn::make('user.username')->label('Пользователь')->searchable(),
                TextColumn::make('scope_type')->label('Область')->formatStateUsing(fn ($state): string => $state->label()),
                TextColumn::make('scope_id')->label('ID области'),
                TextColumn::make('access_level')->label('Роль')->formatStateUsing(fn (string $state): string => VenueMembershipAccessLevelEnum::tryFrom($state)?->label() ?? $state),
                TextColumn::make('contract.status')->label('Статус')->badge()->formatStateUsing(fn ($state): string => $state->label()),
                TextColumn::make('rights')->label('Полномочия')->state(fn (ContractMembership $r): string =>
                    $r->contract?->permissions->map(fn ($p) => VenuePermissionEnum::tryFrom($p->permission)?->label() ?? $p->permission)->join(', ') ?: 'Нет'),
                TextColumn::make('contract.expires_at')->label('До')->dateTime('d.m.Y H:i')->placeholder('Бессрочно'),
            ])
            ->filters([
                Filter::make('status')->schema([
                    Select::make('status')->label('Статус')->options(collect(ContractStatusEnum::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()),
                ])->query(fn ($query, array $data) => $query->when($data['status'] ?? null,
                    fn ($q, $status) => $q->whereHas('contract', fn ($c) => $c->where('status', $status)))),
            ])->defaultSort('id', 'desc');
    }
}
