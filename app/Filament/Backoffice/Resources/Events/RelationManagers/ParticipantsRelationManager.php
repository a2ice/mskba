<?php

namespace App\Filament\Backoffice\Resources\Events\RelationManagers;

use App\Filament\Backoffice\Resources\Events\EventResource;
use App\Modules\Event\Application\UseCases\AddEventParticipantHandler;
use App\Modules\Event\Application\UseCases\UpdateManagedEventParticipantStatusHandler;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Models\EventParticipant;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\CheckboxList;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class ParticipantsRelationManager extends RelationManager
{
    protected static string $relationship = 'participants';
    protected static ?string $title = 'Участники';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return EventResource::canView($ownerRecord);
    }

    public function form(Schema $schema): Schema { return $schema->components([]); }

    /** @return list<string> */
    public static function participationRolesForEdit(EventParticipant $participant): array
    {
        // Pre-migration rows have NULL (not an explicit empty set). They
        // counted as players before role selection was introduced.
        return $participant->participation_roles ?? ['player'];
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('user.username')->label('Пользователь')->searchable(),
            TextColumn::make('activity_roles_label')->label('В качестве кого')
                ->state(fn (EventParticipant $record): string => implode(', ', array_map(
                    fn (string $role): string => \App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum::tryFrom($role)?->label() ?? $role,
                    $record->participation_roles ?? [],
                )) ?: ($record->participation_roles === null
                    ? 'Не указано (учитывается как игрок)' : '—')),
            TextColumn::make('role')->label('Роль')->formatStateUsing(
                fn ($state): string => $state instanceof EventParticipantRoleEnum ? $state->label() : '—'),
            TextColumn::make('status')->label('Статус')->badge()->formatStateUsing(
                fn ($state, EventParticipant $record): string => $record->role === EventParticipantRoleEnum::ORGANIZER
                    && $record->status === EventParticipantStatusEnum::LEFT
                    ? 'Не участвует' : ($state instanceof EventParticipantStatusEnum ? $state->label() : '—')),
            TextColumn::make('joined_at')->label('Записался')->dateTime('d.m.Y H:i'),
        ])->recordActions([
            Action::make('changeStatus')->label('Изменить участие')
                ->visible(fn (EventParticipant $record): bool => EventResource::authorized()
                    && $record->role !== EventParticipantRoleEnum::ORGANIZER
                    && $this->getOwnerRecord()->ends_at->isFuture())
                ->fillForm(fn (EventParticipant $record): array => [
                    'status' => $record->status->value,
                    'participation_roles' => self::participationRolesForEdit($record),
                ])
                ->schema([
                    Select::make('status')->label('Статус')->required()
                        ->options(collect(EventParticipantStatusEnum::cases())->mapWithKeys(
                            fn ($case): array => [$case->value => $case->label()])->all()),
                    CheckboxList::make('participation_roles')->label('В качестве кого')
                        ->options(EventResource::participationRoleOptions())
                        ->required()->minItems(1)->columns(2)
                        ->helperText('Можно выбрать несколько ролей. При подтверждении только роль «Игрок» занимает игровое место.'),
                ])
                ->action(function (EventParticipant $record, array $data): void {
                    abort_unless(EventResource::authorized(), 403);
                    $actor = app(CurrentActorResolver::class)->resolve(auth('web')->user(), null);
                    abort_unless($actor !== null, 403);
                    try {
                        app(UpdateManagedEventParticipantStatusHandler::class)->handle(
                            $this->getOwnerRecord()->routeIdentifier(), $record->id, $actor,
                            EventParticipantStatusEnum::from($data['status']),
                            asBackoffice: true,
                            participationRoles: $data['participation_roles'] ?? [],
                        );
                    } catch (InvalidArgumentException $exception) {
                        throw ValidationException::withMessages(['participation_roles' => $exception->getMessage()]);
                    }
                    Notification::make()->success()->title('Участие и роли обновлены')->send();
                }),
        ])->headerActions([
            Action::make('addParticipant')->label('Добавить участника')
                ->visible(fn (): bool => EventResource::authorized()
                    && $this->getOwnerRecord()->ends_at->isFuture())
                ->schema([
                    Select::make('user_id')->label('Пользователь')->required()->searchable()->live()
                        ->afterStateUpdated(fn (Set $set, $state) => $set(
                            'participation_roles',
                            $state ? EventResource::defaultParticipationRoles((int) $state) : [],
                        ))
                        ->getSearchResultsUsing(fn (string $search): array => User::query()
                            ->whereLike('username', '%'.$search.'%')
                            ->limit(30)->pluck('username', 'id')->all())
                        ->getOptionLabelUsing(fn ($value): ?string => User::query()
                            ->whereKey($value)->value('username')),
                    CheckboxList::make('participation_roles')->label('В качестве кого')
                        ->options(EventResource::participationRoleOptions())
                        ->default([])
                        ->required()->minItems(1)
                        ->visible(fn (Get $get): bool => (int) ($get('user_id') ?? 0) > 0)
                        ->helperText('Можно выбрать несколько ролей. Игрок занимает место после подтверждения участия; остальные роли — нет.')
                        ->columns(2),
                ])
                ->action(function (array $data): void {
                    abort_unless(EventResource::authorized(), 403);
                    $actor = app(CurrentActorResolver::class)->resolve(auth('web')->user(), null);
                    abort_unless($actor !== null, 403);
                    try {
                        app(AddEventParticipantHandler::class)->handle(
                            $this->getOwnerRecord()->routeIdentifier(), $actor, (int) $data['user_id'],
                            asBackoffice: true,
                            participationRoles: $data['participation_roles'] ?? [],
                        );
                    } catch (InvalidArgumentException $exception) {
                        throw ValidationException::withMessages(['participation_roles' => $exception->getMessage()]);
                    }
                    Notification::make()->success()->title('Приглашение участнику добавлено')->send();
                }),
        ]);
    }
}
