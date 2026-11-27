<?php

namespace App\Filament\Backoffice\Resources\Venues\RelationManagers;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueAssignedManagerService;
use App\Modules\Venue\Domain\Models\VenueAssignedManager;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class AssignedManagersRelationManager extends VenueContractsRelationManager
{
    protected static string $relationship = 'assignedManagers';
    protected static ?string $title = 'Закреплённые менеджеры';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('user.username')->label('Менеджер MSKBA')->searchable(),
                TextColumn::make('state')->label('Статус')->badge()->state(fn (VenueAssignedManager $r): string =>
                    $r->active_marker !== true ? 'Завершено' : (app(VenueAssignedManagerService::class)->eligible($r->user) ? 'Назначен' : 'Недоступен')),
                TextColumn::make('assigned_at')->label('Назначен')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('ended_at')->label('Завершено')->dateTime('d.m.Y H:i')->placeholder('—'),
                TextColumn::make('assigned_by_user_id')->label('Кем назначен')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ended_by_user_id')->label('Кем отозван')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->emptyStateHeading('Закреплённый менеджер не назначен')
            ->headerActions([
                Action::make('assignManager')->label('Назначить менеджера')->icon('heroicon-o-user-plus')
                    ->visible(fn (): bool => $this->mutableVenue())
                    ->modalDescription('Со стороны портала MSKBA. Предыдущее назначение завершится, история сохранится. Полномочия площадки и комиссии не изменятся.')
                    ->schema([$this->candidateField(true)])
                    ->action(fn (array $data) => $this->perform(
                        fn () => app(VenueAssignedManagerService::class)->assign($this->getOwnerRecord(),
                            User::query()->findOrFail($data['user_id']), $this->actor()), 'Менеджер назначен')),
            ])
            ->recordActions([
                Action::make('revokeManager')->label('Снять назначение')->color('danger')->requiresConfirmation()
                    ->visible(fn (VenueAssignedManager $r): bool => $r->active_marker === true)
                    ->action(fn (VenueAssignedManager $record) => $this->perform(
                        fn () => app(VenueAssignedManagerService::class)->revoke($this->getOwnerRecord(), $record, $this->actor()), 'Назначение снято')),
            ])->defaultSort('id', 'desc');
    }
}
