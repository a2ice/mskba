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
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
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
                            ->searchable()->required()
                            ->default(fn (): ?int => auth('web')->id())
                            ->getSearchResultsUsing(fn (string $search): array => User::query()
                                ->whereLike('username', '%'.$search.'%')
                                ->limit(30)->pluck('username', 'id')->all())
                            ->getOptionLabelUsing(fn ($value): ?string => User::query()
                                ->whereKey($value)->value('username'))
                            ->visible(fn (?Event $record): bool => $record === null
                                && auth('web')->user()?->canonical()->system_role === UserSystemRoleEnum::SUPERADMIN),
                        Textarea::make('description')->label('Описание')->maxLength(5000)->columnSpanFull(),
                        TextInput::make('max_participants')->label('Максимум участников')
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
                    Section::make('Бронирование')->description(
                        'При создании слот резервируется штатным обработчиком. Занятость зала проверяется до сохранения.'
                    )->columns(2)->schema([
                        Select::make('venue_id')->label('Площадка')->required()->searchable()->live()
                            ->options(fn (): array => Venue::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->afterStateUpdated(fn (Set $set): mixed => $set('venue_court_id', null)),
                        Select::make('venue_court_id')->label('Игровая зона / зал')->searchable()
                            ->options(fn (Get $get): array => VenueCourt::query()
                                ->where('venue_id', $get('venue_id') ?: 0)->orderBy('sort_order')
                                ->pluck('name', 'id')->all()),
                        Select::make('booking_scope')->label('Бронирование')
                            ->options(self::options(VenueBookingScopeEnum::class))->default('whole'),
                        DateTimePicker::make('starts_at')->label('Начало (МСК)')->required()->seconds(false),
                        TextInput::make('duration_minutes')->label('Длительность, минут')->numeric()
                            ->required()->minValue(1)->maxValue(1440)->default(90),
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
            TextColumn::make('participants_count')->counts('participants')->label('Участников'),
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
