<?php

namespace App\Filament\Backoffice\Resources\Venues\RelationManagers;

use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Venue\Domain\Enums\VenueCourtEnvironmentEnum;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueSurfaceTypeEnum;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Application\Services\VenueCourtGalleryManager;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\Venue\Application\Services\EffectiveCourtPolicy;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\FileUpload;
use Filament\Infolists\Components\TextEntry;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\HtmlString;

final class CourtsRelationManager extends RelationManager
{
    protected static string $relationship = 'courts';
    protected static ?string $title = 'Игровые зоны / залы';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return VenueResource::authorized() && ! $ownerRecord->trashed();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
            Section::make('Игровая зона')->columns(2)->schema([
                TextInput::make('name')->label('Название')->required()->maxLength(120),
                TextInput::make('alias')->label('URL-алиас')->required()->maxLength(120)
                    ->regex('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D')
                    ->rule(fn (?VenueCourt $record) => Rule::unique('venue_courts', 'alias')
                        ->where('venue_id', $this->getOwnerRecord()->getKey())->ignore($record?->getKey())),
                TextInput::make('sort_order')->label('Порядок')->numeric()->minValue(0)->maxValue(65535)->default(10),
                Select::make('environment')->label('Тип зала / зоны')->options(
                    collect(VenueCourtEnvironmentEnum::cases())->mapWithKeys(fn ($case): array =>
                        [$case->value => $case->label()])->all()
                )->required(),
                Select::make('hoops_count')->label('Колец')->options([1 => '1', 2 => '2']),
                Select::make('surface_type')->label('Покрытие')->options(
                    collect(VenueSurfaceTypeEnum::cases())->mapWithKeys(fn ($case): array =>
                        [$case->value => $case->label()])->all()
                ),
                Toggle::make('supports_halves')->label('Разделяется на половины')->default(false),
                Toggle::make('allows_whole')->label('Бронирование целиком')->default(true),
                Toggle::make('allows_halves')->label('Бронирование половин')->default(false)
                    ->helperText('Включайте только для зоны с двумя кольцами и поддержкой половин.'),
                Select::make('operational_status')->label('Статус зала')
                    ->options(['inherit' => 'Наследовать от площадки',
                        'active' => 'Работает', 'temporarily_closed' => 'Временно закрыт'])
                    ->default('inherit')
                    ->formatStateUsing(fn ($state): string => $state?->value ?? (is_string($state) ? $state : 'inherit'))
                    ->dehydrateStateUsing(fn ($state): ?string => $state === 'inherit' ? null : $state)
                    ->required(),
                Select::make('requires_payment')->label('Платность')
                    ->options(['inherit' => 'Наследовать от площадки', 'yes' => 'Платно', 'no' => 'Бесплатно'])
                    ->default('inherit')
                    ->formatStateUsing(fn ($state): string => $state === null ? 'inherit' : ($state ? 'yes' : 'no'))
                    ->dehydrateStateUsing(fn ($state): ?bool => $state === 'inherit' ? null : $state === 'yes')
                    ->required(),
                Select::make('requires_booking')->label('Предварительное бронирование')
                    ->options(['inherit' => 'Наследовать от площадки', 'yes' => 'Обязательно', 'no' => 'Свободный доступ'])
                    ->default('inherit')
                    ->formatStateUsing(fn ($state): string => $state === null ? 'inherit' : ($state ? 'yes' : 'no'))
                    ->dehydrateStateUsing(fn ($state): ?bool => $state === 'inherit' ? null : $state === 'yes')
                    ->required(),
                Select::make('requires_booking_approval')->label('Подтверждение бронирования')
                    ->options(['inherit' => 'Наследовать от площадки', 'yes' => 'Требуется', 'no' => 'Автоматически'])
                    ->default('inherit')
                    ->formatStateUsing(fn ($state): string => $state === null ? 'inherit' : ($state ? 'yes' : 'no'))
                    ->dehydrateStateUsing(fn ($state): ?bool => $state === 'inherit' ? null : $state === 'yes')
                    ->required(),
                Select::make('rental_enabled')->label('Аренда зала')
                    ->options(['inherit' => 'Наследовать от площадки', 'yes' => 'Разрешена', 'no' => 'Отключена'])
                    ->default('inherit')
                    ->formatStateUsing(fn ($state): string => $state === null ? 'inherit' : ($state ? 'yes' : 'no'))
                    ->dehydrateStateUsing(fn ($state): ?bool => $state === 'inherit' ? null : $state === 'yes')
                    ->required(),
                TextInput::make('whole_price_per_step_minor')->label('Цена всего зала, коп. за шаг')
                    ->numeric()->minValue(0)->maxValue(999999999)->nullable()
                    ->helperText('Пусто — наследуется стоимость площадки. 0 — бесплатно.'),
                TextInput::make('half_price_per_step_minor')->label('Цена половины, коп. за шаг')
                    ->numeric()->minValue(0)->maxValue(999999999)->nullable()
                    ->helperText('Пусто — наследуется стоимость площадки.'),
            ]),
            Section::make('Наследуемые условия зала')
                ->description('Без переопределения зал автоматически следует настройкам площадки. Цена указывается за шаг аренды, в копейках; пустое поле — наследование.')
                ->columns(2)->schema([
                    Select::make('operational_status')->label('Состояние зала')
                        ->options(['inherit' => 'Наследовать от площадки',
                            'active' => 'Работает', 'temporarily_closed' => 'Временно закрыт'])
                        ->default('inherit')
                        ->afterStateHydrated(fn (Select $component, $state) => $component->state(
                            $state instanceof VenueOperationalStatusEnum ? $state->value : ($state ?: 'inherit')
                        ))
                        ->dehydrateStateUsing(fn ($state) => $state === 'inherit' ? null : $state),
                    $this->inheritedBoolean('requires_payment', 'Оплата', 'Платно', 'Бесплатно'),
                    $this->inheritedBoolean('requires_booking', 'Запись', 'Бронирование обязательно', 'Свободный доступ'),
                    $this->inheritedBoolean('requires_booking_approval', 'Подтверждение заявки', 'Требуется', 'Автоматически'),
                    $this->inheritedBoolean('rental_enabled', 'Онлайн-бронирование', 'Разрешено', 'Запрещено'),
                    TextInput::make('whole_price_per_step_minor')
                        ->label('Цена всего зала за шаг, коп.')->numeric()->minValue(0)
                        ->helperText('Пусто — наследовать цену площадки. Ноль — явно бесплатно.'),
                    TextInput::make('half_price_per_step_minor')
                        ->label('Цена половины за шаг, коп.')->numeric()->minValue(0)
                        ->helperText('Пусто — наследовать цену площадки.'),
                ]),
            Section::make('Медиатека')->description('Фотографии именно этого зала / зоны')
                ->schema([
                    TextEntry::make('court_photo_preview')->label('Фотографии')
                        ->state(fn (?VenueCourt $record): HtmlString => $this->courtPhotoPreview($record))
                        ->html(),
                ]),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Зал / зона')->searchable(),
            ImageColumn::make('cover')->label('Фото')->getStateUsing(
                fn (VenueCourt $record): ?string => ($photo = $record->media()
                    ->where('collection', 'gallery')->orderByDesc('is_featured')
                    ->orderBy('sort_order')->first()) ? url($photo->publicUrl()) : null
            )->square()->height(48),
            TextColumn::make('effective_payment')->label('Аренда')->getStateUsing(
                fn (VenueCourt $record): string => $record->requires_payment === null
                    ? 'Наследуется' : ($record->requires_payment ? 'Платно' : 'Бесплатно')
            ),
            TextColumn::make('photo_count')->label('Фотографий')->getStateUsing(
                fn (VenueCourt $record): int => $record->media()->where('collection', 'gallery')->count()
            ),
            TextColumn::make('alias')->label('Код'),
            TextColumn::make('requires_payment')->label('Оплата')
                ->formatStateUsing(fn ($state): string => $state === null
                    ? 'Наследуется' : ($state ? 'Платно' : 'Бесплатно')),
            TextColumn::make('operational_status')->label('Состояние')
                ->formatStateUsing(fn ($state): string => $state instanceof VenueOperationalStatusEnum
                    ? $state->label() : 'Наследуется'),
            TextColumn::make('whole_price_per_step_minor')->label('Цена/шаг, коп.')
                ->placeholder('Наследуется'),
            TextColumn::make('environment')->label('Тип зала / зоны')
                ->formatStateUsing(fn ($state): string => $state instanceof VenueCourtEnvironmentEnum
                    ? $state->label() : (VenueCourtEnvironmentEnum::tryFrom((string) $state)?->label() ?? 'Не указан')),
            IconColumn::make('is_primary')->label('Основная')->boolean(),
            TextColumn::make('hoops_count')->label('Колец'),
            TextColumn::make('surface_type')->label('Покрытие')
                ->formatStateUsing(fn ($state): string => $state instanceof VenueSurfaceTypeEnum
                    ? $state->label() : (VenueSurfaceTypeEnum::tryFrom((string) $state)?->label() ?? '—')),
            IconColumn::make('allows_whole')->label('Целиком')->boolean(),
            IconColumn::make('allows_halves')->label('Половины')->boolean(),
        ])->headerActions([
            CreateAction::make()->label('Добавить зал')
                ->visible(fn (): bool => VenueResource::authorized()),
        ])->recordActions([
            EditAction::make(),
            Action::make('courtPricePeriods')->label('Тарифные интервалы')
                ->icon('heroicon-o-currency-dollar')
                ->schema([
                    Repeater::make('prices')->label('Цены зала по дням и времени')
                        ->helperText('Приоритет: интервал зала → базовая цена зала → интервал площадки → базовая цена площадки.')
                        ->schema([
                            Select::make('day_of_week')->label('День')->required()
                                ->options([
                                    1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда',
                                    4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье',
                                ]),
                            TimePicker::make('starts_at')->label('Начало шага')->required()->seconds(false),
                            TextInput::make('whole_price_per_step_minor')->label('Весь зал, коп.')
                                ->numeric()->minValue(0),
                            TextInput::make('half_price_per_step_minor')->label('Половина, коп.')
                                ->numeric()->minValue(0),
                        ])->columns(4),
                ])
                ->fillForm(fn (VenueCourt $record): array => [
                    'prices' => $record->slotPrices()->orderBy('day_of_week')->orderBy('starts_at')
                        ->get()->map(fn ($price): array => [
                            'day_of_week' => $price->day_of_week,
                            'starts_at' => substr((string) $price->starts_at, 0, 5),
                            'whole_price_per_step_minor' => $price->whole_price_per_step_minor,
                            'half_price_per_step_minor' => $price->half_price_per_step_minor,
                        ])->all(),
                ])
                ->action(function (VenueCourt $record, array $data): void {
                    abort_unless(VenueResource::authorized(), 403);
                    $rows = array_values($data['prices'] ?? []);
                    $keys = [];
                    foreach ($rows as $row) {
                        $key = $row['day_of_week'].'|'.substr((string) $row['starts_at'], 0, 5);
                        if (isset($keys[$key])) {
                            throw ValidationException::withMessages([
                                'prices' => 'Один и тот же тарифный шаг указан дважды.',
                            ]);
                        }
                        $keys[$key] = true;
                        if (($row['whole_price_per_step_minor'] ?? null) === null
                            && ($row['half_price_per_step_minor'] ?? null) === null) {
                            throw ValidationException::withMessages([
                                'prices' => 'Укажите цену хотя бы для одного типа аренды.',
                            ]);
                        }
                    }
                    DB::transaction(function () use ($record, $rows): void {
                        $record->slotPrices()->delete();
                        foreach ($rows as $row) {
                            $record->slotPrices()->create($row);
                        }
                    });
                }),
            Action::make('uploadCourtPhoto')->label('Добавить фото')->icon('heroicon-o-photo')
                ->schema([
                    FileUpload::make('photo')->label('Фотография зала')->image()
                        ->storeFiles(false)->required(),
                ])
                ->action(function (VenueCourt $record, array $data): void {
                    abort_unless(VenueResource::authorized(), 403);
                    $file = $data['photo'] ?? null;
                    $contents = $file?->getRealPath() ? file_get_contents($file->getRealPath()) : false;
                    if (! is_string($contents)) {
                        throw ValidationException::withMessages(['photo' => 'Не удалось прочитать изображение.']);
                    }
                    app(VenueCourtGalleryManager::class)->store($record, $contents);
                }),
            Action::make('setCourtCover')->label('Обложка')->icon('heroicon-o-star')
                ->schema([
                    Select::make('media_id')->label('Основная фотография')->required()
                        ->options(fn (VenueCourt $record): array => $this->courtPhotoOptions($record)),
                ])
                ->visible(fn (VenueCourt $record): bool => $record->media()->where('collection', 'gallery')->exists())
                ->action(function (VenueCourt $record, array $data): void {
                    abort_unless(VenueResource::authorized(), 403);
                    app(VenueCourtGalleryManager::class)->activate($record, (int) $data['media_id']);
                }),
            Action::make('removeCourtPhoto')->label('Удалить фото')->icon('heroicon-o-trash')
                ->color('danger')
                ->schema([
                    Select::make('media_id')->label('Фотография')->required()
                        ->options(fn (VenueCourt $record): array => $this->courtPhotoOptions($record)),
                ])
                ->visible(fn (VenueCourt $record): bool => $record->media()->where('collection', 'gallery')->exists())
                ->requiresConfirmation()
                ->action(function (VenueCourt $record, array $data): void {
                    abort_unless(VenueResource::authorized(), 403);
                    app(VenueCourtGalleryManager::class)->delete($record, (int) $data['media_id']);
                }),
            Action::make('editCourtSchedule')->label('Расписание')->icon('heroicon-o-clock')
                ->schema([
                    Toggle::make('override')->label('Индивидуальный график зала')
                        ->helperText('Выключено: зал автоматически наследует действующий график площадки.')
                        ->live(),
                    Repeater::make('weekly_intervals')->label('Часы работы по дням недели')
                        ->schema([
                            Select::make('day_of_week')->label('День')->required()->options([
                                1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда',
                                4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье',
                            ]),
                            TimePicker::make('starts_at')->label('С')->required()->seconds(false),
                            TimePicker::make('ends_at')->label('До')->required()->seconds(false),
                        ])->columns(3)->visible(fn (Get $get): bool => (bool) $get('override')),
                    Repeater::make('exceptions')->label('Исключения и нерабочие дни')
                        ->schema([
                            DatePicker::make('date')->label('Дата')->required(),
                            Toggle::make('is_closed')->label('Не работает')->live(),
                            TimePicker::make('starts_at')->label('С')->seconds(false)
                                ->required(fn (Get $get): bool => ! (bool) $get('is_closed')),
                            TimePicker::make('ends_at')->label('До')->seconds(false)
                                ->required(fn (Get $get): bool => ! (bool) $get('is_closed')),
                        ])->columns(4)->visible(fn (Get $get): bool => (bool) $get('override')),
                ])
                ->fillForm(fn (VenueCourt $record): array => [
                    'override' => $record->schedule !== null,
                    'weekly_intervals' => $record->schedule?->weekly_intervals ?? [],
                    'exceptions' => $record->schedule?->exceptions ?? [],
                ])
                ->action(function (VenueCourt $record, array $data): void {
                    abort_unless(VenueResource::authorized(), 403);
                    if (! ($data['override'] ?? false)) {
                        $record->schedule()->delete();
                        $record->unsetRelation('schedule');

                        return;
                    }
                    $weekly = array_values($data['weekly_intervals'] ?? []);
                    $exceptions = array_values($data['exceptions'] ?? []);
                    foreach (array_merge($weekly, $exceptions) as $row) {
                        if (! ($row['is_closed'] ?? false)
                            && (empty($row['starts_at']) || empty($row['ends_at'])
                                || $row['starts_at'] >= $row['ends_at'])) {
                            throw ValidationException::withMessages([
                                'weekly_intervals' => 'Окончание интервала должно быть позже начала.',
                            ]);
                        }
                    }
                    $record->schedule()->updateOrCreate(
                        ['venue_court_id' => $record->id],
                        ['weekly_intervals' => $weekly, 'exceptions' => $exceptions],
                    );
                    $record->unsetRelation('schedule');
                }),
            Action::make('makePrimary')->label('Сделать основной')->icon('heroicon-o-star')
                ->visible(fn (VenueCourt $record): bool => ! $record->is_primary)
                ->requiresConfirmation()
                ->action(function (VenueCourt $record): void {
                    abort_unless(VenueResource::authorized(), 403);
                    DB::transaction(function () use ($record): void {
                        $ownerId = $this->getOwnerRecord()->getKey();
                        VenueCourt::query()->where('venue_id', $ownerId)->lockForUpdate()->get();
                        $court = VenueCourt::query()->where('venue_id', $ownerId)
                            ->whereKey($record->getKey())->firstOrFail();
                        VenueCourt::query()->where('venue_id', $ownerId)->where('is_primary', true)
                            ->update(['is_primary' => false]);
                        $court->update(['is_primary' => true]);
                    });
                }),
            DeleteAction::make()->requiresConfirmation()
                ->visible(fn (VenueCourt $record): bool => ! $record->is_primary
                    && ! $this->hasFutureUsage($record))
                ->before(function (VenueCourt $record): void {
                    abort_unless(VenueResource::authorized(), 403);
                    abort_if($record->is_primary || $this->hasFutureUsage($record), 422,
                        'Основной зал или зал с будущими бронированиями/мероприятиями нельзя удалить.');
                }),
        ])->defaultSort('sort_order');
    }

    private function inheritedBoolean(
        string $field, string $label, string $yes, string $no,
    ): Select {
        return Select::make($field)->label($label)->options([
            'inherit' => 'Наследовать от площадки',
            'yes' => $yes,
            'no' => $no,
        ])->default('inherit')
            ->afterStateHydrated(fn (Select $component, $state) => $component->state(
                $state === null || $state === 'inherit' ? 'inherit' : ($state ? 'yes' : 'no')
            ))
            ->dehydrateStateUsing(fn ($state) => match ($state) {
                'yes' => true, 'no' => false, default => null,
            });
    }

    private function courtPhotoPreview(?VenueCourt $court): HtmlString
    {
        if ($court === null) {
            return new HtmlString('Сохраните зал, чтобы добавить фотографии.');
        }

        $photos = $court->media()->where('collection', 'gallery')
            ->orderByDesc('is_featured')->orderBy('sort_order')->orderBy('id')->get();
        if ($photos->isEmpty()) {
            return new HtmlString('Фотографий ещё нет. Добавьте их действием «Добавить фото» в таблице залов.');
        }

        $markup = $photos->map(fn ($photo): string =>
            '<img src="'.e(url($photo->publicUrl())).'" alt="Фото зала" loading="lazy" '
            .'style="width:100%;max-width:150px;height:110px;object-fit:cover;border-radius:9px">'
        )->implode('');

        return new HtmlString('<div style="display:flex;gap:8px;flex-wrap:wrap">'.$markup.'</div>'
            .'<p style="margin-top:10px;font-size:12px">Добавление, выбор обложки и удаление — действия строки зала в таблице.</p>');
    }

    /** @return array<int, string> */
    private function courtPhotoOptions(VenueCourt $court): array
    {
        return $court->media()->where('collection', 'gallery')
            ->orderByDesc('is_featured')->orderBy('sort_order')->orderBy('id')
            ->get()->mapWithKeys(fn ($photo): array => [
                $photo->id => 'Фото #'.$photo->id.($photo->is_featured ? ' (обложка)' : ''),
            ])->all();
    }

    private function hasFutureUsage(VenueCourt $court): bool
    {
        return DB::table('venue_bookings')->where('venue_court_id', $court->getKey())
            ->where('ends_at', '>', now())->exists()
            || DB::table('venue_booking_quotes')->where('venue_court_id', $court->getKey())
                ->where('starts_at', '>', now())->exists()
            || DB::table('events')->where('venue_court_id', $court->getKey())
                ->where('starts_at', '>', now())->exists();
    }
}
