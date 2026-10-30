<?php

namespace App\Filament\Backoffice\Resources\Teams\RelationManagers;

use App\Filament\Backoffice\Resources\Teams\BackofficeTeamMembers;
use App\Filament\Backoffice\Resources\Teams\TeamResource;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamLineupAssignmentEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Models\TeamSportProfile;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class LineupsRelationManager extends RelationManager
{
    protected static string $relationship = 'sportProfiles';
    protected static ?string $title = 'Стартовый состав';
    protected static string|\BackedEnum|null $icon = 'heroicon-o-clipboard-document-list';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return TeamResource::canView($ownerRecord) && ! $ownerRecord->trashed();
    }

    public function form(Schema $schema): Schema { return $schema->components([]); }

    private function playerOptions(): array
    {
        return $this->getOwnerRecord()->memberships()
            ->withSportRole(TeamMemberTypeEnum::PLAYER)
            ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
            ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
            ->with('user')->orderBy('id')->get()->mapWithKeys(fn ($membership): array =>
                [$membership->id => ($membership->user?->username ?? '#'.$membership->user_id)
                    .($membership->jersey_number === null ? '' : ' · №'.$membership->formattedJerseyNumber())])
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('sport_type')->label('Формат')->badge()
                ->formatStateUsing(fn ($state): string => $state->label()),
            TextColumn::make('starters_label')->label('Стартовый состав')
                ->state(fn (TeamSportProfile $record): string => $record->lineupMembers()
                    ->with('membership.user')
                    ->where('assignment', TeamLineupAssignmentEnum::STARTER->value)
                    ->orderBy('position')->get()
                    ->map(fn ($row) => $row->membership?->user?->username ?: '—')->implode(', ') ?: 'Не назначен'),
            TextColumn::make('reserves_count')->label('Запасные')
                ->state(fn (TeamSportProfile $record): int => $record->lineupMembers()
                    ->where('assignment', TeamLineupAssignmentEnum::RESERVE->value)->count()),
        ])->recordActions([
            Action::make('configureStarters')->label('Настроить состав')
                ->icon('heroicon-o-adjustments-horizontal')
                ->visible(fn (): bool => TeamResource::canEdit($this->getOwnerRecord()))
                ->fillForm(fn (TeamSportProfile $record): array => [
                    'starter_ids' => $record->lineupMembers()
                        ->where('assignment', TeamLineupAssignmentEnum::STARTER->value)
                        ->orderBy('position')->pluck('contract_membership_id')
                        ->map(fn ($id): string => (string) $id)->all(),
                ])
                ->schema([
                    CheckboxList::make('starter_ids')->label('Игроки стартового состава')
                        ->options(fn (): array => $this->playerOptions())->columns(2)
                        ->helperText('Выберите 3 игрока для стритбола 3×3 либо 5 для баскетбола 5×5. Остальные активные игроки станут запасными. Для неполной команды можно оставить состав незаполненным.'),
                ])
                ->action(function (TeamSportProfile $record, array $data): void {
                    app(BackofficeTeamMembers::class)->saveLineup(
                        $this->getOwnerRecord(), $record->sport_type, $data['starter_ids'] ?? [],
                    );
                    Notification::make()->success()->title('Стартовый состав сохранён')->send();
                }),
        ]);
    }
}
