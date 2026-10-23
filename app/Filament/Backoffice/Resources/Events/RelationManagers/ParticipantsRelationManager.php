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

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('user.username')->label('Пользователь')->searchable(),
            TextColumn::make('role')->label('Роль')->formatStateUsing(
                fn ($state): string => $state instanceof EventParticipantRoleEnum ? $state->label() : '—'),
            TextColumn::make('status')->label('Статус')->badge()->formatStateUsing(
                fn ($state): string => $state instanceof EventParticipantStatusEnum ? $state->label() : '—'),
            TextColumn::make('joined_at')->label('Записался')->dateTime('d.m.Y H:i'),
        ])->recordActions([
            Action::make('changeStatus')->label('Изменить участие')
                ->visible(fn (EventParticipant $record): bool => EventResource::authorized()
                    && $record->role !== EventParticipantRoleEnum::ORGANIZER
                    && $this->getOwnerRecord()->ends_at->isFuture())
                ->schema([
                    Select::make('status')->label('Статус')->required()
                        ->options(collect(EventParticipantStatusEnum::cases())->mapWithKeys(
                            fn ($case): array => [$case->value => $case->label()])->all()),
                ])
                ->action(function (EventParticipant $record, array $data): void {
                    abort_unless(EventResource::authorized(), 403);
                    $actor = app(CurrentActorResolver::class)->resolve(auth('web')->user(), null);
                    abort_unless($actor !== null, 403);
                    app(UpdateManagedEventParticipantStatusHandler::class)->handle(
                        $this->getOwnerRecord()->routeIdentifier(), $record->id, $actor,
                        EventParticipantStatusEnum::from($data['status']), asBackoffice: true);
                    Notification::make()->success()->title('Статус участника обновлён')->send();
                }),
        ])->headerActions([
            Action::make('addParticipant')->label('Добавить участника')
                ->visible(fn (): bool => EventResource::authorized()
                    && $this->getOwnerRecord()->ends_at->isFuture())
                ->schema([
                    Select::make('user_id')->label('Пользователь')->required()->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => User::query()
                            ->whereLike('username', '%'.$search.'%')
                            ->limit(30)->pluck('username', 'id')->all())
                        ->getOptionLabelUsing(fn ($value): ?string => User::query()
                            ->whereKey($value)->value('username')),
                ])
                ->action(function (array $data): void {
                    abort_unless(EventResource::authorized(), 403);
                    $actor = app(CurrentActorResolver::class)->resolve(auth('web')->user(), null);
                    abort_unless($actor !== null, 403);
                    app(AddEventParticipantHandler::class)->handle(
                        $this->getOwnerRecord()->routeIdentifier(), $actor, (int) $data['user_id'],
                        asBackoffice: true);
                    Notification::make()->success()->title('Участник добавлен')->send();
                }),
        ]);
    }
}
