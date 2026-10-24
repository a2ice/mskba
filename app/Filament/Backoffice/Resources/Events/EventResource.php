<?php

namespace App\Filament\Backoffice\Resources\Events;

use App\Filament\Backoffice\Resources\Events\Pages\CreateEvent;
use App\Filament\Backoffice\Resources\Events\Pages\EditEvent;
use App\Filament\Backoffice\Resources\Events\Pages\ListEvents;
use App\Filament\Backoffice\Resources\Events\RelationManagers\ParticipantsRelationManager;
use App\Filament\Backoffice\Resources\Events\RelationManagers\GamesRelationManager;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use App\Modules\Event\Domain\Enums\GameTimingModeEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Models\UserParticipationRole;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class EventResource extends Resource
{
    protected static ?string $model = Event::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Контент';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?int $navigationSort = 3;
    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationLabel(): string { return 'Мероприятия'; }
    public static function getModelLabel(): string { return 'мероприятие'; }
    public static function getPluralModelLabel(): string { return 'мероприятия'; }

    public static function authorized(): bool
    {
        return app(EventBackofficeEditor::class)->authorized(auth('web')->user());
    }

    public static function canViewAny(): bool { return self::authorized(); }
    public static function canView(Model $record): bool { return $record instanceof Event && self::authorized(); }
    public static function canCreate(): bool { return self::authorized(); }
    public static function canEdit(Model $record): bool
    {
        return self::canView($record)
            && ! $record->trashed()
            && ! in_array($record->status, [EventStatusEnum::CANCELLED, EventStatusEnum::COMPLETED], true)
            && $record->ends_at->isFuture()
            && ! $record->trainingSession()->exists();
    }
    public static function canDelete(Model $record): bool
    {
        return self::canView($record)
            && ! $record->trashed()
            && in_array($record->status, [EventStatusEnum::DRAFT, EventStatusEnum::CANCELLED], true)
            && ! $record->booking()->whereIn('status', VenueBookingStatusEnum::occupyingValues())->exists()
            && ! $record->sourceBooking()->exists()
            && ! $record->trainingSession()->exists();
    }
    public static function canRestore(Model $record): bool { return self::canView($record) && $record->trashed(); }
    public static function canDeleteAny(): bool { return false; }
    public static function canForceDelete(Model $record): bool { return false; }
    public static function canForceDeleteAny(): bool { return false; }

    /** @return array<string, string> */
    private static function options(string $enum): array
    {
        return collect($enum::cases())->mapWithKeys(
            fn ($case): array => [$case->value => $case->label()],
        )->all();
    }

    /** @return array<int, string> */
    public static function durationOptions(): array
    {
        $options = [];
        foreach (range(30, 480, 30) as $minutes) {
            $hours = intdiv($minutes, 60);
            $part = $minutes % 60;
            $options[$minutes] = $hours === 0 ? '30 минут'
                : ($part === 0 ? "{$hours} ч" : "{$hours} ч 30 мин");
        }

        return $options;
    }

    public static function isFreeAccess(Get $get): bool
    {
        $venueId = (int) ($get('venue_id') ?? 0);

        return $venueId > 0 && Venue::query()->whereKey($venueId)->first()?->hasFreeAccess() === true;
    }

    /** @return array<string, string> */
    public static function participationRoleOptions(): array
    {
        return collect(UserParticipationRoleEnum::cases())
            ->reject(fn (UserParticipationRoleEnum $role): bool => $role === UserParticipationRoleEnum::ORGANIZER)
            ->mapWithKeys(fn (UserParticipationRoleEnum $role): array => [$role->value => $role->label()])
            ->all();
    }

    /** @return list<string> */
    public static function defaultParticipationRoles(?int $organizerUserId = null): array
    {
        $organizer = $organizerUserId
            ? User::query()->find($organizerUserId)?->canonical()
            : auth('web')->user()?->canonical();

        if ($organizer === null) {
            return [UserParticipationRoleEnum::PLAYER->value];
        }

        $allowed = array_keys(self::participationRoleOptions());
        $roles = UserParticipationRole::query()
            ->whereIn('user_id', $organizer->identityIds())
            ->where('status', UserParticipationRoleStatusEnum::ACTIVE->value)
            ->whereIn('role', $allowed)
            ->pluck('role')
            ->map(fn ($role): string => $role instanceof UserParticipationRoleEnum ? $role->value : (string) $role)
            ->unique()->values()->all();

        return $roles !== [] ? $roles : [UserParticipationRoleEnum::PLAYER->value];
    }

    public static function defaultEventDate(): string
    {
        return now('Europe/Moscow')->toDateString();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Мероприятие')->persistTabInQueryString()->tabs([
                Tab::make('Основное')->schema([
                    Section::make('Мероприятие')->columns(2)->schema([
                        TextInput::make('title')->label('Название')->required()->maxLength(150)->columnSpanFull(),
                        Select::make('type')->label('Тип')->required()->live()
                            ->options(self::options(EventTypeEnum::class))->default(EventTypeEnum::TRAINING->value)
                            ->disabled(fn (?Event $record): bool => $record?->primaryGame()->exists() ?? false)
                            ->dehydrated(),
                        Select::make('visibility')->label('Видимость')->required()
                            ->options(self::options(EventVisibilityEnum::class))
                            ->default(EventVisibilityEnum::PUBLIC->value),
                        Select::make('organizer_user_id')->label('Организатор')
                            ->searchable()->live()
                            ->afterStateUpdated(fn (Set $set, $state) => $set(
                                'participation_roles',
                                self::defaultParticipationRoles((int) ($state ?? 0) ?: null),
                            ))
                            // Keep the selected option in the current option set.
                            // Search results alone do not satisfy Filament validation
                            // for a programmatically hydrated selection.
                            ->options(fn (Get $get): array => ($selected = (int) ($get('organizer_user_id') ?? 0))
                                ? User::query()->whereKey($selected)->get()->mapWithKeys(
                                    fn (User $user): array => [$user->id => $user->username ?: 'Пользователь #'.$user->id],
                                )->all()
                                : [])
                            ->placeholder('Текущий администратор')
                            ->helperText('Можно не выбирать — организатором станет текущий пользователь.')
                            ->getSearchResultsUsing(fn (string $search): array => User::query()
                                ->whereLike('username', '%'.$search.'%')
                                ->limit(30)->get()->mapWithKeys(
                                    fn (User $user): array => [$user->id => $user->username ?: 'Пользователь #'.$user->id],
                                )->all())
                            ->getOptionLabelUsing(function ($value): ?string {
                                $user = User::query()->find($value);

                                return $user ? ($user->username ?: 'Пользователь #'.$user->id) : null;
                            })
                            ->visible(fn (?Event $record): bool => $record === null
                                && auth('web')->user()?->canonical()->system_role === UserSystemRoleEnum::SUPERADMIN),
                        Checkbox::make('organizer_participates')->label('Буду участвовать')
                            ->default(false)->live()->columnSpanFull()
                            ->visible(fn (?Event $record): bool => $record === null)
                            ->helperText('Организация мероприятия не означает участие в нём.'),
                        CheckboxList::make('participation_roles')->label('В качестве кого')
                            ->options(self::participationRoleOptions())
                            ->default(fn (Get $get): array => self::defaultParticipationRoles(
                                (int) ($get('organizer_user_id') ?? 0) ?: null,
                            ))
                            ->required(fn (Get $get): bool => (bool) $get('organizer_participates'))
                            ->minItems(1)
                            ->visible(fn (Get $get, ?Event $record): bool =>
                                $record === null && (bool) $get('organizer_participates'))
                            ->columns(2)->columnSpanFull(),
                        Textarea::make('description')->label('Описание')->maxLength(5000)->columnSpanFull(),
                        TextInput::make('max_participants')->label('Максимум игроков')
                            ->numeric()->minValue(2)->maxValue(500),
                    ]),
                    Section::make('Состояние')->columns(2)->schema([
                        TextInput::make('status')->label('Статус')->disabled()->dehydrated(false)
                            ->formatStateUsing(fn ($state): string => $state instanceof EventStatusEnum
                                ? $state->label() : (EventStatusEnum::tryFrom((string) $state)?->label() ?? '—')),
                        TextInput::make('organizer_label')->label('Организатор')
                            ->disabled()->dehydrated(false),
                    ])->visible(fn (?Event $record): bool => $record !== null),
                    Section::make('Связанная бронь')->columns(3)->schema([
                        TextInput::make('booking_record_id')->label('ID брони')
                            ->disabled()->dehydrated(false),
                        TextInput::make('booking_status_label')->label('Статус брони')
                            ->disabled()->dehydrated(false),
                        TextInput::make('source_booking_id')->label('Внешнее бронирование')
                            ->disabled()->dehydrated(false),
                    ])->visible(fn (?Event $record): bool => $record !== null),
                ]),
                Tab::make('Площадка и время')->schema([
                    Section::make(fn (Get $get): string => self::isFreeAccess($get)
                        ? 'Использование площадки' : 'Бронирование площадки')
                        ->description(fn (Get $get): string => self::isFreeAccess($get)
                            ? 'Свободная площадка: дополнительное согласование не требуется. Указанный интервал используется для планирования мероприятия.'
                            : 'Мероприятие связано с заявкой на бронирование. Доступность зала проверяется перед сохранением.')
                        ->columns(2)->schema([
                        Select::make('venue_id')->label('Площадка')->required()->searchable()->live()
                            ->options(fn (): array => Venue::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->afterStateUpdated(fn (Set $set): mixed => $set('venue_court_id', null)),
                        Select::make('venue_court_id')->label('Игровая зона / зал')->searchable()
                            ->options(fn (Get $get): array => VenueCourt::query()
                                ->where('venue_id', $get('venue_id') ?: 0)->orderBy('sort_order')
                                ->pluck('name', 'id')->all())
                            ->disabled(fn (Get $get): bool => ! VenueCourt::query()
                                ->where('venue_id', $get('venue_id') ?: 0)->exists())
                            ->helperText(fn (Get $get): ?string => $get('venue_id') && ! VenueCourt::query()
                                ->where('venue_id', $get('venue_id'))->exists()
                                ? 'На площадке нет отдельных игровых зон. Мероприятие охватит всю площадку.'
                                : null),
                        Select::make('booking_scope')->label('Охват площадки')
                            ->options(self::options(VenueBookingScopeEnum::class))->default('whole'),
                        DatePicker::make('event_date')->label('Дата')
                            ->default(fn (): string => self::defaultEventDate())
                            ->required()->native(false),
                        TimePicker::make('event_time')->label('Время')
                            ->required()->seconds(false)->native(false),
                        Select::make('duration_minutes')->label('Продолжительность')
                            ->options(self::durationOptions())->native(false)
                            ->required()->default(90)
                            ->helperText('От 30 минут до 8 часов, с шагом 30 минут.'),
                    ]),
                ]),
                Tab::make('Параметры игры')->visible(
                    fn (Get $get, ?Event $record): bool =>
                        $get('type') === EventTypeEnum::GAME->value || $record?->type === EventTypeEnum::GAME
                )->schema([
                    Section::make('Игра')->columns(2)->schema([
                        Select::make('game_format')->label('Формат')
                            ->options(self::options(GameFormatEnum::class))->default('streetball_3x3'),
                        Select::make('game_recruitment_mode')->label('Набор игроков')
                            ->options(self::options(GameRecruitmentModeEnum::class))->default('preformed_teams'),
                        Toggle::make('game_accepts_applications')->label('Принимать заявки')->default(true),
                        TextInput::make('side_a_size')->label('Игроков в команде A')->numeric()
                            ->minValue(1)->maxValue(7)->default(3),
                        TextInput::make('side_b_size')->label('Игроков в команде Б')->numeric()
                            ->minValue(1)->maxValue(7)->default(3),
                        Select::make('scoring_type')->label('Подсчёт очков')
                            ->options(self::options(GameScoringTypeEnum::class))->default('streetball'),
                        Select::make('timing_mode')->label('Режим времени')
                            ->options(self::options(GameTimingModeEnum::class))->default('whole_game'),
                        Select::make('periods_count')->label('Периодов')->options([2 => '2', 4 => '4']),
                    ])->disabled(fn (?Event $record): bool => $record !== null)
                      ->description('После создания настройки игры и её состав управляются в игровом контуре.'),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->label('Мероприятие')->searchable()->sortable(),
            TextColumn::make('type')->label('Тип')->badge()
                ->formatStateUsing(fn ($state): string => $state instanceof EventTypeEnum ? $state->label() : '—'),
            TextColumn::make('status')->label('Статус')->badge()
                ->formatStateUsing(fn ($state): string => $state instanceof EventStatusEnum ? $state->label() : '—'),
            TextColumn::make('venue.name')->label('Площадка')->searchable(),
            TextColumn::make('starts_at')->label('Начало')->dateTime('d.m.Y H:i')->sortable(),
            TextColumn::make('ends_at')->label('Окончание')->dateTime('d.m.Y H:i'),
            TextColumn::make('players_count')->counts(['participants as players_count' => fn ($query) => $query->occupiesPlayerSpot()])->label('Игроков'),
        ])->filters([
            SelectFilter::make('type')->label('Тип')->options(self::options(EventTypeEnum::class)),
            SelectFilter::make('status')->label('Статус')->options(self::options(EventStatusEnum::class)),
            TrashedFilter::make()->label('Удалённые'),
        ])->defaultSort('starts_at', 'desc')->recordActions([
            EditAction::make(),
            Action::make('open')->label('Открыть на сайте')->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn (Event $record): string => route('events.show', $record->routeIdentifier()))
                ->openUrlInNewTab(),
            Action::make('cancel')->label('Отменить')->color('danger')->icon('heroicon-o-no-symbol')
                ->visible(fn (Event $record): bool => self::canEdit($record))
                ->schema([Textarea::make('reason')->label('Причина отмены')->maxLength(1000)])
                ->requiresConfirmation()
                ->action(function (Event $record, array $data): void {
                    app(EventBackofficeEditor::class)->cancelEvent($record, $data['reason'] ?? null);
                    Notification::make()->success()->title('Мероприятие отменено')->send();
                }),
            Action::make('complete')->label('Завершить')->icon('heroicon-o-check-badge')
                ->visible(fn (Event $record): bool => self::canView($record)
                    && $record->status === EventStatusEnum::PUBLISHED && $record->ends_at->isPast())
                ->schema([Textarea::make('description')->label('Итоги')->maxLength(5000)])
                ->requiresConfirmation()
                ->action(function (Event $record, array $data): void {
                    app(EventBackofficeEditor::class)->completeEvent($record, $data['description'] ?? null);
                    Notification::make()->success()->title('Мероприятие завершено')->send();
                }),
            DeleteAction::make()->requiresConfirmation(),
            RestoreAction::make(),
        ]);
    }

    public static function getRelations(): array
    {
        return [ParticipantsRelationManager::class, GamesRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListEvents::route('/'), 'create' => CreateEvent::route('/create'),
            'edit' => EditEvent::route('/{record}/edit')];
    }
}
