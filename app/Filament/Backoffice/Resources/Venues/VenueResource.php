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
use App\Modules\Venue\Domain\Enums\VenueMarkingConditionEnum;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Amenity;
use App\Modules\Venue\Domain\Models\Venue;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
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
                Tab::make('Основное')->schema([
                    Section::make('Карточка площадки')->columns(2)->schema([
                        TextInput::make('name')->label('Название')->required()->minLength(3)->maxLength(255),
                        TextInput::make('alias')->label('URL-алиас')->maxLength(255)
                            ->regex('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D')
                            ->helperText('Можно оставить пустым при создании — алиас будет сформирован автоматически. URL использует ID площадки.'),
                        Select::make('type')->label('Тип')->required()->options(self::options(VenueTypeEnum::class)),
                        Select::make('status')->label('Статус модерации')->required()
                            ->options(self::options(VenueStatusEnum::class))->default(VenueStatusEnum::UNCONFIRMED->value),
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
                            TextInput::make('raw_address')->label('Адрес для отображения')->maxLength(1000)->columnSpanFull(),
                            TextInput::make('location_city')->label('Город')->maxLength(255),
                            TextInput::make('location_street')->label('Улица')->maxLength(255),
                            TextInput::make('location_building')->label('Дом / корпус')->maxLength(255),
                            TextInput::make('location_postal_code')->label('Индекс')->maxLength(32),
                            TextInput::make('location_latitude')->label('Широта')->numeric()->minValue(-90)->maxValue(90),
                            TextInput::make('location_longitude')->label('Долгота')->numeric()->minValue(-180)->maxValue(180),
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
        ])->defaultSort('id', 'desc')->recordActions([
            EditAction::make(),
            DeleteAction::make()->requiresConfirmation(),
            RestoreAction::make(),
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
