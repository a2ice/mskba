<?php

namespace App\Filament\Backoffice\Resources\Venues;

use App\Filament\Backoffice\Resources\Venues\Pages\CreateVenue;
use App\Filament\Backoffice\Resources\Venues\Pages\EditVenue;
use App\Filament\Backoffice\Resources\Venues\Pages\ListVenues;
use App\Filament\Backoffice\Resources\Venues\RelationManagers\CourtsRelationManager;
use App\Filament\Backoffice\Resources\Venues\RelationManagers\PhotosRelationManager;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Location\Domain\Models\MetroStation;
use App\Modules\Location\Domain\Models\City;
use App\Modules\Location\Domain\Models\District;
use App\Modules\Venue\Domain\Enums\VenueMarkingConditionEnum;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Amenity;
use App\Modules\Venue\Domain\Models\Venue;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Filters\Filter;
use Filament\Notifications\Notification;
use App\Modules\Venue\Application\UseCases\BackofficeVenueModerationHandler;
use App\Modules\Venue\Application\UseCases\UpdateVenueStatusHandler;
use App\Modules\Venue\Application\UseCases\BulkChangeVenueDeletionStateHandler;
use App\Modules\Venue\Application\UseCases\BulkUpdateVenueStatusHandler;
use App\Modules\Moderation\Domain\Enums\ModerationRequestStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class VenueResource extends Resource
{
    protected static ?string $model = Venue::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Контент';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';
    protected static ?int $navigationSort = 2;
    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string { return 'Площадки'; }
    public static function getModelLabel(): string { return 'площадка'; }
    public static function getPluralModelLabel(): string { return 'площадки'; }

    public static function authorized(): bool
    {
        $user = auth('web')->user();

        return $user !== null
            && app(BackofficePermissionManager::class)->allows($user, Permission::BACKOFFICE_ACCESS)
            && app(BackofficePermissionManager::class)->allows($user, Permission::BACKOFFICE_VENUE_MANAGE);
    }

    public static function canViewAny(): bool { return self::authorized(); }
    public static function canView(Model $record): bool { return $record instanceof Venue && self::authorized(); }
    public static function canCreate(): bool { return self::authorized(); }
    public static function canEdit(Model $record): bool { return $record instanceof Venue && ! $record->trashed() && self::authorized(); }
    public static function canDelete(Model $record): bool { return self::canEdit($record); }
    public static function canRestore(Model $record): bool { return $record instanceof Venue && $record->trashed() && self::authorized(); }
    public static function canDeleteAny(): bool { return false; }
    public static function canForceDelete(Model $record): bool { return false; }
    public static function canForceDeleteAny(): bool { return false; }

    private static function options(string $enum): array
    {
        return collect($enum::cases())->mapWithKeys(fn ($case): array => [$case->value => $case->label()])->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Tabs::make('Площадка')->persistTabInQueryString()->tabs([
                Tab::make('Баннер')->icon('heroicon-o-photo')
                    ->visible(fn ($record): bool => $record !== null && self::canEdit($record))
                    ->schema([View::make('filament.backoffice.entity-banner')]),

                Tab::make('Основное')->schema([
                    Section::make('Карточка площадки')->columns(2)->schema([
                        TextInput::make('name')->label('Название')->required()->minLength(3)->maxLength(255),
                        TextInput::make('alias')->label('URL-алиас')->maxLength(255)
                            ->regex('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D')
                            ->helperText('Можно оставить пустым при создании — алиас будет сформирован автоматически. URL использует ID площадки.'),
                        Select::make('type')->label('Тип')->required()->options(self::options(VenueTypeEnum::class)),
                        Select::make('status')->label('Статус модерации')->required()->live()
                            ->options(function (?Venue $record): array {
                                $options = $record?->status === VenueStatusEnum::CONFIRMED
                                    ? [
                                        VenueStatusEnum::CONFIRMED->value => VenueStatusEnum::CONFIRMED->label(),
                                        VenueStatusEnum::BLOCKED->value => VenueStatusEnum::BLOCKED->label(),
                                    ] : [
                                        VenueStatusEnum::UNCONFIRMED->value => VenueStatusEnum::UNCONFIRMED->label(),
                                        VenueStatusEnum::BLOCKED->value => VenueStatusEnum::BLOCKED->label(),
                                    ];
                                if (($record === null || $record->status === VenueStatusEnum::UNCONFIRMED)
                                    && app(BackofficeVenueModerationHandler::class)
                                        ->canApproveImmediately(auth('web')->user())) {
                                    $options[VenueStatusEnum::CONFIRMED->value] = 'Подтверждён (через модерацию)';
                                }
                                return $options;
                            })
                            ->default(VenueStatusEnum::UNCONFIRMED->value)
                            ->helperText('Superadmin может выбрать «Подтверждён»: заявка и её одобрение будут записаны автоматически.'),
                        Textarea::make('status_info')->label('Комментарий к статусу (причина блокировки)')
                            ->maxLength(5000)->columnSpanFull(),
                        Select::make('operational_status')->label('Состояние работы')->required()
                            ->options(self::options(VenueOperationalStatusEnum::class))
                            ->default(VenueOperationalStatusEnum::ACTIVE->value),
                        Select::make('access_type')->label('Оплата доступа')->required()
                            ->options(['unknown' => 'Не указано', 'free' => 'Бесплатно', 'paid' => 'Платно'])
                            ->default('unknown'),
                        Toggle::make('requires_booking_approval')->label('Нужна предварительная запись')
                            ->default(false)->columnSpanFull(),
                        Textarea::make('short_description')->label('Краткое описание')->maxLength(500)->columnSpanFull(),
                        Textarea::make('full_description')->label('Полное описание')->maxLength(10000)->rows(6)->columnSpanFull(),
                        TextInput::make('tags_text')->label('Теги')->maxLength(1000)->columnSpanFull()
                            ->helperText('Через запятую. Связанные теги сохраняются в стандартной таблице площадки.'),
                    ]),
                ]),
                Tab::make('Адрес и метро')->schema([
                    Section::make('Адрес')
                        ->description('Адрес создаётся как отдельная Location/Address. Изменение адреса не переписывает адреса других объектов.')
                        ->columns(2)->schema([
                            Select::make('address_yandex_selection')
                                ->label('Полный адрес')
                                ->live()
                                ->placeholder('Начните вводить адрес')
                                ->searchable()
                                ->searchDebounce(400)
                                ->searchPrompt('Введите хотя бы три символа для поиска через Яндекс')
                                ->getSearchResultsUsing(fn (string $search): array =>
                                    app(VenueAddressAutocomplete::class)->search($search))
                                ->getOptionLabelUsing(fn (?string $value): ?string =>
                                    VenueAddressAutocomplete::label($value))
                                ->afterStateUpdated(function (?string $state, Set $set): void {
                                    foreach (VenueAddressAutocomplete::fields($state) as $name => $value) {
                                        $set($name, $value);
                                    }
                                })
                                ->dehydrated(false)
                                ->helperText('Выберите адрес из подсказок Яндекса — остальные поля заполнятся автоматически.')
                                ->columnSpanFull(),
                            Hidden::make('location_full_address'),
                            TextInput::make('raw_address')->label('Отображаемый адрес')->maxLength(1000)
                                ->helperText('Улица, дом и строение без индекса и города. Можно изменить вручную.')
                                ->columnSpanFull(),
                            TextInput::make('location_city')->label('Город')->maxLength(255),
                            Select::make('location_city_id')->label('Город из справочника')->searchable()
                                ->options(fn (): array => City::query()->orderBy('name')->pluck('name', 'id')->all()),
                            Select::make('location_district_id')->label('Район из справочника')->searchable()
                                ->options(fn (): array => District::query()->orderBy('name')->pluck('name', 'id')->all()),
                            TextInput::make('location_street')->label('Улица')->maxLength(255),
                            TextInput::make('location_building')->label('Дом / корпус')->maxLength(255),
                            TextInput::make('location_postal_code')->label('Индекс')->maxLength(32),
                            TextInput::make('location_latitude')->label('Широта')->numeric()->minValue(-90)->maxValue(90)
                                ->required(fn (Get $get): bool => $get('status') === VenueStatusEnum::CONFIRMED->value)
                                ->helperText('Обязательна для подтверждения площадки.'),
                            TextInput::make('location_longitude')->label('Долгота')->numeric()->minValue(-180)->maxValue(180)
                                ->required(fn (Get $get): bool => $get('status') === VenueStatusEnum::CONFIRMED->value)
                                ->helperText('Обязательна для подтверждения площадки.'),
                            Select::make('location_metro_ids')->label('Станции метро')->multiple()->searchable()
                                ->options(fn (): array => MetroStation::query()->orderBy('name')->pluck('name', 'id')->all())
                                ->columnSpanFull(),
                        ]),
                ]),
                Tab::make('Удобства')->schema([
                    Section::make('Характеристики')->columns(2)->schema([
                        Select::make('characteristics.hoops_count')->label('Количество колец')
                            ->options([1 => '1', 2 => '2']),
                        Select::make('characteristics.hoops_condition')->label('Состояние колец')
                            ->options([1 => '1 — очень плохое', 2 => '2', 3 => '3', 4 => '4', 5 => '5 — отличное']),
                        Select::make('characteristics.surface_condition')->label('Состояние покрытия')
                            ->options([1 => '1 — очень плохое', 2 => '2', 3 => '3', 4 => '4', 5 => '5 — отличное']),
                        Select::make('characteristics.marking_condition')->label('Разметка')
                            ->options(self::options(VenueMarkingConditionEnum::class)),
                    ]),
                    Section::make('Доступные удобства')->schema([
                        CheckboxList::make('amenity_ids')->label('Удобства')->columns(2)
                            ->options(fn (): array => Amenity::query()->where('is_active', true)
                                ->orderBy('sort_order')->pluck('name', 'id')->all())
                            ->helperText('Тип площадки должен соответствовать назначению удобства (зал или улица).'),
                    ]),
                ]),
                Tab::make('Расписание')->schema([
                    Section::make('Регулярная неделя')
                        ->description('Интервалы редактируются вместе с площадкой; время указывается по её часовому поясу.')
                        ->schema([
                            TextInput::make('schedule_timezone')->label('Часовой пояс')->default('Europe/Moscow')->maxLength(64),
                            Repeater::make('weekly_intervals')->label('Интервалы работы')
                                ->defaultItems(0)->columns(3)->schema([
                                    Select::make('day_of_week')->label('День')->required()
                                        ->options([1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс']),
                                    TimePicker::make('starts_at')->label('С')->seconds(false)->required(),
                                    TimePicker::make('ends_at')->label('До')->seconds(false)->required(),
                                ])->columnSpanFull(),
                        ]),
                    Section::make('Исключения по датам')->schema([
                        Repeater::make('schedule_exceptions')->label('Исключения')->defaultItems(0)
                            ->schema([
                                DatePicker::make('date')->label('Дата')->required(),
                                Toggle::make('is_closed')->label('Закрыта весь день')->default(false),
                                Repeater::make('intervals')->label('Часы работы в этот день')->defaultItems(0)->columns(2)
                                    ->schema([
                                        TimePicker::make('starts_at')->label('С')->seconds(false)->required(),
                                        TimePicker::make('ends_at')->label('До')->seconds(false)->required(),
                                    ]),
                            ]),
                    ]),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('name')->label('Площадка')->searchable()->sortable(),
            TextColumn::make('type')->label('Тип')->formatStateUsing(
                fn ($state): string => $state instanceof VenueTypeEnum ? $state->label() : (VenueTypeEnum::tryFrom((string) $state)?->label() ?? '—')),
            TextColumn::make('status')->label('Статус')->badge()->formatStateUsing(
                fn ($state): string => $state instanceof VenueStatusEnum ? $state->label() : (VenueStatusEnum::tryFrom((string) $state)?->label() ?? '—')),
            TextColumn::make('raw_address')->label('Адрес')->limit(65)->searchable(),
            TextColumn::make('updated_at')->label('Изменена')->dateTime('d.m.Y H:i')->sortable(),
        ])->filters([
            SelectFilter::make('type')->label('Тип')->options(self::options(VenueTypeEnum::class)),
            SelectFilter::make('status')->label('Статус')->options(self::options(VenueStatusEnum::class)),
            TrashedFilter::make()->label('Удалённые'),
            Filter::make('pending_moderation')->label('На модерации')
                ->query(fn (Builder $query): Builder => $query->whereHas('moderationRequests',
                    fn (Builder $requests): Builder => $requests->where(
                        'status', ModerationRequestStatusEnum::PENDING->value))),
        ])->defaultSort('id', 'desc')->recordActions([
            EditAction::make(),
            Action::make('approve_now')->label('Подтвердить')->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (Venue $record): bool => $record->status === VenueStatusEnum::UNCONFIRMED
                    && app(BackofficeVenueModerationHandler::class)->canApproveImmediately(auth('web')->user()))
                ->requiresConfirmation()
                ->action(function (Venue $record): void {
                    abort_unless(app(BackofficeVenueModerationHandler::class)
                        ->canApproveImmediately(auth('web')->user()), 403);
                    try {
                        app(BackofficeVenueModerationHandler::class)->approveImmediately($record, auth('web')->user());
                        Notification::make()->success()->title('Площадка подтверждена через модерацию')->send();
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            Action::make('block')->label('Заблокировать')->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->visible(fn (Venue $record): bool => self::canEdit($record)
                    && $record->status !== VenueStatusEnum::BLOCKED)
                ->schema([Textarea::make('message')->label('Причина блокировки')->required()->maxLength(5000)])
                ->requiresConfirmation()
                ->action(function (Venue $record, array $data): void {
                    abort_unless(self::canEdit($record), 403);
                    app(UpdateVenueStatusHandler::class)->handle(
                        $record, VenueStatusEnum::BLOCKED, (string) $data['message']);
                    Notification::make()->success()->title('Площадка заблокирована')->send();
                }),
            Action::make('unblock')->label('Разблокировать')->icon('heroicon-o-lock-open')
                ->visible(fn (Venue $record): bool => self::canEdit($record)
                    && $record->status === VenueStatusEnum::BLOCKED)
                ->requiresConfirmation()
                ->action(function (Venue $record): void {
                    abort_unless(self::canEdit($record), 403);
                    app(UpdateVenueStatusHandler::class)->handle(
                        $record, VenueStatusEnum::UNCONFIRMED);
                    Notification::make()->success()->title('Площадка разблокирована')->send();
                }),
            DeleteAction::make()->requiresConfirmation(),
            RestoreAction::make(),
        ])->toolbarActions([
            BulkActionGroup::make([
                BulkAction::make('block_selected')->label('Заблокировать выбранные')
                    ->color('danger')->icon('heroicon-o-no-symbol')
                    ->schema([Textarea::make('message')->label('Причина')->required()->maxLength(5000)])
                    ->requiresConfirmation()
                    ->action(function (Collection $records, array $data): void {
                        abort_unless(self::authorized(), 403);
                        app(BulkUpdateVenueStatusHandler::class)->handle(
                            $records->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                            VenueStatusEnum::BLOCKED, (string) $data['message']);
                    })->deselectRecordsAfterCompletion(),
                BulkAction::make('unblock_selected')->label('Разблокировать выбранные')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        abort_unless(self::authorized(), 403);
                        app(BulkUpdateVenueStatusHandler::class)->handle(
                            $records->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                            VenueStatusEnum::UNCONFIRMED);
                    })->deselectRecordsAfterCompletion(),
                BulkAction::make('delete_selected')->label('Удалить выбранные')
                    ->color('danger')->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        abort_unless(self::authorized(), 403);
                        app(BulkChangeVenueDeletionStateHandler::class)->delete(
                            $records->pluck('id')->map(fn ($id): int => (int) $id)->all());
                    })->deselectRecordsAfterCompletion(),
                BulkAction::make('restore_selected')->label('Восстановить выбранные')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        abort_unless(self::authorized(), 403);
                        app(BulkChangeVenueDeletionStateHandler::class)->restore(
                            $records->pluck('id')->map(fn ($id): int => (int) $id)->all());
                    })->deselectRecordsAfterCompletion(),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            CourtsRelationManager::class,
            PhotosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVenues::route('/'),
            'create' => CreateVenue::route('/create'),
            'edit' => EditVenue::route('/{record}/edit'),
        ];
    }
}
