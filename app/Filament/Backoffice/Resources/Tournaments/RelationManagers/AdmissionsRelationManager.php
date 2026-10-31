<?php

namespace App\Filament\Backoffice\Resources\Tournaments\RelationManagers;

use App\Filament\Backoffice\Resources\Tournaments\TournamentResource;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Tournament\Application\Services\TournamentAdmissionService;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionDirectionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Tournament\Domain\Models\TournamentAdmission;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class AdmissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'admissions';
    protected static ?string $title = 'Заявки и приглашения';
    protected static string|\BackedEnum|null $icon = 'heroicon-o-envelope';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return TournamentResource::canView($ownerRecord);
    }

    public function form(Schema $schema): Schema { return $schema->components([]); }

    private function actor(): Actor
    {
        abort_unless(TournamentResource::canManageParticipants(), 403);

        return app(CurrentActorResolver::class)->resolve(auth('web')->user()?->canonical(), null)
            ?? abort(403);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('team.name')->label('Команда')->placeholder('—'),
            TextColumn::make('user.username')->label('Игрок')->placeholder('—'),
            TextColumn::make('direction')->label('Тип')->badge()->formatStateUsing(
                fn ($state): string => $state === TournamentAdmissionDirectionEnum::APPLICATION ? 'Заявка' : 'Приглашение',
            ),
            TextColumn::make('status')->label('Статус')->badge()
                ->formatStateUsing(fn ($state): string => $state instanceof TournamentAdmissionStatusEnum ? $state->label() : '—'),
            TextColumn::make('created_at')->label('Получена')->dateTime('d.m.Y H:i')->sortable(),
        ])->defaultSort('id', 'desc')
        ->headerActions([
            Action::make('inviteTeam')->label('Пригласить команду')->icon('heroicon-o-user-plus')
                ->visible(fn (): bool => TournamentResource::canManageParticipants()
                    && $this->getOwnerRecord()->recruitment_mode === TournamentRecruitmentModeEnum::PREFORMED_TEAMS
                    && $this->getOwnerRecord()->acceptsAdmissions())
                ->schema([
                    Select::make('team_id')->label('Команда')->required()->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Team::query()
                            ->competitionEligible()->whereLike('name', '%'.$search.'%')
                            ->limit(30)->pluck('name', 'id')->all())
                        ->getOptionLabelUsing(fn ($id): ?string => Team::query()->whereKey($id)->value('name')),
                ])->action(function (array $data): void {
                    $tournament = $this->getOwnerRecord();
                    app(TournamentAdmissionService::class)->invite(
                        $tournament, $this->actor(), Team::query()->findOrFail($data['team_id']),
                    );
                    Notification::make()->success()->title('Приглашение отправлено')->send();
                }),
            Action::make('invitePlayer')->label('Пригласить игрока')->icon('heroicon-o-user-plus')
                ->visible(fn (): bool => TournamentResource::canManageParticipants()
                    && $this->getOwnerRecord()->recruitment_mode === TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT
                    && $this->getOwnerRecord()->acceptsAdmissions())
                ->schema([
                    Select::make('user_id')->label('Игрок')->required()->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => User::query()
                            ->whereNull('canonical_user_id')->whereLike('username', '%'.$search.'%')
                            ->limit(30)->pluck('username', 'id')->all())
                        ->getOptionLabelUsing(fn ($id): ?string => User::query()->whereKey($id)->value('username')),
                ])->action(function (array $data): void {
                    app(TournamentAdmissionService::class)->invite(
                        $this->getOwnerRecord(), $this->actor(), User::query()->findOrFail($data['user_id']),
                    );
                    Notification::make()->success()->title('Приглашение отправлено')->send();
                }),
        ])
        ->recordActions([
            Action::make('acceptApplication')->label('Принять заявку')->icon('heroicon-o-check')
                ->visible(fn (TournamentAdmission $record): bool =>
                    TournamentResource::canManageParticipants()
                    && $record->direction === TournamentAdmissionDirectionEnum::APPLICATION
                    && $record->status === TournamentAdmissionStatusEnum::PENDING)
                ->requiresConfirmation()
                ->action(function (TournamentAdmission $record): void {
                    app(TournamentAdmissionService::class)->respond(
                        $this->getOwnerRecord(), $record, $this->actor(), TournamentAdmissionStatusEnum::ACCEPTED,
                    );
                    Notification::make()->success()->title('Заявка принята')->send();
                }),
            Action::make('declineApplication')->label('Отклонить заявку')->icon('heroicon-o-x-mark')
                ->color('danger')->requiresConfirmation()
                ->visible(fn (TournamentAdmission $record): bool =>
                    TournamentResource::canManageParticipants()
                    && $record->direction === TournamentAdmissionDirectionEnum::APPLICATION
                    && $record->status === TournamentAdmissionStatusEnum::PENDING)
                ->action(function (TournamentAdmission $record): void {
                    app(TournamentAdmissionService::class)->respond(
                        $this->getOwnerRecord(), $record, $this->actor(), TournamentAdmissionStatusEnum::DECLINED,
                    );
                    Notification::make()->success()->title('Заявка отклонена')->send();
                }),
            Action::make('revokeAdmission')->label('Отозвать допуск')->color('danger')
                ->icon('heroicon-o-no-symbol')->requiresConfirmation()
                ->visible(fn (TournamentAdmission $record): bool =>
                    TournamentResource::canManageParticipants()
                    && in_array($record->status, [
                        TournamentAdmissionStatusEnum::PENDING, TournamentAdmissionStatusEnum::ACCEPTED,
                    ], true))
                ->action(function (TournamentAdmission $record): void {
                    app(TournamentAdmissionService::class)->revoke(
                        $this->getOwnerRecord(), $record, $this->actor(),
                    );
                    Notification::make()->success()->title('Допуск отозван')->send();
                }),
        ]);
    }
}
