<?php

namespace App\Filament\Backoffice\Resources\Venues\Support;

use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Application\Services\VenueTariffGrid;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;

/**
 * The same native Filament tariff modal works on the venue's Schedule tab
 * and in each court's table, without navigating to a separate resource page.
 * Both variants save through VenueTariffGrid; the public editor uses it too.
 */
final class VenueTariffModal
{
    public static function forVenue(string $name = 'editVenueTariffs'): Action
    {
        return self::base($name)
            ->label('Тарифная сетка площадки')
            ->modalHeading('Тарифы площадки по дням недели')
            ->visible(fn (?Venue $record): bool => $record !== null && VenueResource::canEdit($record))
            ->modalContent(fn (Venue $record): View => self::preview($record, null))
            ->action(function (Venue $record, array $data): void {
                abort_unless(VenueResource::canEdit($record), 403);
                self::save($record, null, $data);
            });
    }

    public static function forCourt(Venue $venue, string $name = 'editCourtTariffs'): Action
    {
        return self::base($name)
            ->label('Тарифная сетка')
            ->modalHeading(fn (VenueCourt $record): string => 'Тарифы зала: '.$record->publicName())
            ->visible(fn (VenueCourt $record): bool => VenueResource::canEdit($venue)
                && (int) $record->venue_id === (int) $venue->id)
            ->modalContent(function (VenueCourt $record) use ($venue): View {
                abort_unless((int) $record->venue_id === (int) $venue->id, 403);

                return self::preview($venue, $record);
            })
            ->action(function (VenueCourt $record, array $data) use ($venue): void {
                abort_unless(VenueResource::canEdit($venue)
                    && (int) $record->venue_id === (int) $venue->id, 403);
                self::save($venue, $record, $data);
            });
    }

    private static function base(string $name): Action
    {
        return Action::make($name)
            ->icon('heroicon-o-table-cells')
            ->color('primary')
            ->modalWidth(Width::SixExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Сохранить все диапазоны')
            ->modalDescription('Диапазоны и исключения редактируются прямо здесь. Ручные цены не изменяют импортируемые данные.')
            ->fillForm(fn (): array => [
                'mode' => 'weekly',
                'date' => now('Europe/Moscow')->toDateString(),
                'ranges' => [
                    [
                        'days' => [1, 2, 3, 4, 5],
                        'starts_at' => '07:00',
                        'ends_at' => '16:00',
                        'operation' => 'set',
                        'whole_price_per_step' => null,
                        'half_price_per_step' => null,
                        'exceptions' => [],
                    ],
                ],
            ])
            ->schema(self::schema());
    }

    /** @return list<mixed> */
    private static function schema(): array
    {
        return [
            Section::make('Период изменения')
                ->columns(2)
                ->schema([
                    Select::make('mode')
                        ->label('Тариф')
                        ->options(['weekly' => 'По дням недели', 'date' => 'На конкретную дату'])
                        ->required()
                        ->live(),
                    DatePicker::make('date')
                        ->label('Дата тарифа')
                        ->native(false)
                        ->required(fn (Get $get): bool => $get('mode') === 'date')
                        ->visible(fn (Get $get): bool => $get('mode') === 'date'),
                ]),
            Repeater::make('ranges')
                ->label('Временные диапазоны')
                ->addActionLabel('Добавить диапазон')
                ->reorderable(false)
                ->defaultItems(1)
                ->minItems(1)
                ->maxItems(16)
                ->itemLabel(fn (array $state): string => trim((string) ($state['starts_at'] ?? '')).' — '.trim((string) ($state['ends_at'] ?? '')))
                ->schema([
                    CheckboxList::make('days')
                        ->label('Дни недели')
                        ->options([
                            1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт',
                            5 => 'Пт', 6 => 'Сб', 7 => 'Вс',
                        ])
                        ->default([1, 2, 3, 4, 5])
                        ->columns(7)
                        ->helperText('Для тарифа на конкретную дату используются сутки выбранной даты.')
                        ->columnSpanFull(),
                    TimePicker::make('starts_at')
                        ->label('С')
                        ->seconds(false)
                        ->required(),
                    TimePicker::make('ends_at')
                        ->label('До')
                        ->seconds(false)
                        ->required()
                        ->helperText('Конец не включается. 00:00 — до конца суток.'),
                    Select::make('operation')
                        ->label('Действие')
                        ->options([
                            'set' => 'Назначить цены',
                            'clear' => 'Наследовать (убрать ручную цену)',
                        ])
                        ->default('set')
                        ->required()
                        ->live()
                        ->columnSpanFull(),
                    TextInput::make('whole_price_per_step')
                        ->label('Весь зал, за шаг')
                        ->placeholder('Наследовать')
                        ->helperText('Пусто — наследовать тариф площадки/базовый тариф.')
                        ->regex('/^\d+(?:[.,]\d{1,2})?$/')
                        ->maxLength(32)
                        ->visible(fn (Get $get): bool => $get('operation') === 'set'),
                    TextInput::make('half_price_per_step')
                        ->label('Половина, за шаг')
                        ->placeholder('Наследовать')
                        ->regex('/^\d+(?:[.,]\d{1,2})?$/')
                        ->maxLength(32)
                        ->visible(fn (Get $get): bool => $get('operation') === 'set'),
                    Repeater::make('exceptions')
                        ->label('Исключения внутри диапазона')
                        ->addActionLabel('Добавить исключение')
                        ->defaultItems(0)
                        ->reorderable(false)
                        ->maxItems(100)
                        ->schema([
                            TimePicker::make('starts_at')->label('Начало шага')->seconds(false)->required(),
                            Select::make('day_of_week')->label('День недели')
                                ->options([
                                    0 => 'Все выбранные дни', 1 => 'Пн', 2 => 'Вт',
                                    3 => 'Ср', 4 => 'Чт', 5 => 'Пт',
                                    6 => 'Сб', 7 => 'Вс',
                                ])
                                ->default(0),
                            Select::make('operation')->label('Действие')
                                ->options(['set' => 'Назначить цену', 'clear' => 'Наследовать'])
                                ->default('set')->required()->live(),
                            TextInput::make('whole_price_per_step')->label('Весь зал')
                                ->regex('/^\d+(?:[.,]\d{1,2})?$/')->maxLength(32)
                                ->visible(fn (Get $get): bool => $get('operation') === 'set'),
                            TextInput::make('half_price_per_step')->label('Половина')
                                ->regex('/^\d+(?:[.,]\d{1,2})?$/')->maxLength(32)
                                ->visible(fn (Get $get): bool => $get('operation') === 'set'),
                        ])->columns(5)->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ];
    }

    private static function preview(Venue $venue, ?VenueCourt $court): View
    {
        $policy = self::policy($venue);

        return view('filament.backoffice.venue-tariff-modal-preview', [
            'venue' => $venue,
            'court' => $court,
            'policy' => $policy,
            'step' => $policy === null ? null : app(VenueTariffGrid::class)->step($policy, $court),
            'rows' => $policy === null ? [] : app(VenueTariffGrid::class)->read(
                $venue, $policy, $court, 'weekly', now('Europe/Moscow')->toDateString(),
            ),
        ]);
    }

    private static function policy(Venue $venue): ?VenueBookingPolicy
    {
        return VenueBookingPolicy::query()
            ->where('venue_id', $venue->id)
            ->where('active_marker', true)
            ->first();
    }

    private static function save(Venue $venue, ?VenueCourt $court, array $data): void
    {
        $policy = self::policy($venue);
        if ($policy === null) {
            throw ValidationException::withMessages([
                'mode' => 'Сначала установите базовую цену и условия аренды площадки.',
            ]);
        }

        $count = app(VenueTariffGrid::class)->applyMany(
            $venue, $policy, $court,
            $data['mode'] ?? 'weekly',
            $data['date'] ?? now('Europe/Moscow')->toDateString(),
            array_values($data['ranges'] ?? []),
            auth('web')->id(),
        );

        Notification::make()->success()
            ->title('Тарифная сетка сохранена')
            ->body('Изменено '.$count.' временных шагов. Изменения также доступны в кабинете площадки.')
            ->send();
    }
}
