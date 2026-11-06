<?php

namespace App\Filament\Backoffice\Resources\Venues\RelationManagers;

use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Venue\Application\Services\EffectiveCourtPolicy;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenuePricingRule;
use App\Modules\VenueBooking\Application\Services\MinorAmountParser;
use App\Modules\VenueBooking\Application\Services\VenueCommissionCalculator;
use App\Modules\VenueBooking\Application\Services\VenuePricingRuleEvaluator;
use App\Modules\VenueBooking\Application\Services\VenuePricingRuleManager;
use App\Modules\VenueBooking\Application\Services\VenueSlotPricing;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PricingRulesRelationManager extends RelationManager
{
    protected static string $relationship = 'pricingRules';
    protected static ?string $title = 'Тарифные условия и скидки';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return VenueResource::authorized() && ! $ownerRecord->trashed();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    private function canManagePricingRules(): bool
    {
        $user = auth('web')->user();

        return VenueResource::authorized()
            && $user !== null
            && app(VenueCommercialAccess::class)->allows(
                $user, $this->getOwnerRecord(), VenuePermissionEnum::MANAGE_BOOKING_POLICY,
            );
    }

    /** @return array<int, \Filament\Schemas\Components\Component> */
    private function pricingRuleForm(): array
    {
        return [
            Tabs::make('pricingRuleTabs')->tabs([
                Tab::make('Основное')->schema([
                    Section::make('Тарифное условие')
                        ->columns(['default' => 1, 'md' => 2])
                        ->schema([
                            TextInput::make('name')->label('Название')
                                ->required()->minLength(2)->maxLength(120),
                            Select::make('venue_court_id')->label('Зал')
                                ->placeholder('Все залы площадки')
                                ->options(fn (): array => $this->getOwnerRecord()->courts()
                                    ->pluck('name', 'id')->all())
                                ->disabled(fn (?VenuePricingRule $record): bool => $record !== null)
                                ->dehydrated(),
                            TextInput::make('priority')->label('Приоритет')
                                ->numeric()->integer()->required()->minValue(0)->maxValue(1000)->default(100),
                            Textarea::make('description')->label('Описание')
                                ->rows(2)->maxLength(2000)->columnSpanFull(),
                            Toggle::make('is_enabled')->label('Активно после публикации')
                                ->default(true),
                            Toggle::make('stop_processing')->label('Не применять следующие правила')
                                ->default(false),
                        ]),
                ]),
                Tab::make('Условия')->schema([
                    Section::make('Ограничения применения')
                        ->columns(['default' => 1, 'md' => 2])
                        ->schema([
                            Select::make('scope')->label('Формат аренды')
                                ->options([
                                    'all' => 'Любой',
                                    'whole' => 'Весь зал',
                                    'half' => 'Половина зала',
                                ])
                                ->default('all')->required(),
                            TextInput::make('min_duration_minutes')
                                ->label('Длительность от, мин')->numeric()->integer()
                                ->minValue(1)->maxValue(1440),
                            TextInput::make('max_duration_minutes')
                                ->label('Длительность до, мин')->numeric()->integer()
                                ->minValue(1)->maxValue(1440),
                            TextInput::make('min_steps')
                                ->label('Минимум шагов аренды')->numeric()->integer()
                                ->minValue(1)->maxValue(288),
                            DatePicker::make('date_from')->label('Период с'),
                            DatePicker::make('date_to')->label('Период по'),
                            TimePicker::make('time_from')->label('Время с')->seconds(false),
                            TimePicker::make('time_to')->label('Время до')->seconds(false),
                            TextInput::make('min_base_minor')
                                ->label('Базовая цена от, коп.')->numeric()->integer()
                                ->minValue(0)->maxValue(1000000000000),
                            CheckboxList::make('weekdays')->label('Дни недели')
                                ->options([
                                    1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт',
                                    5 => 'Пт', 6 => 'Сб', 7 => 'Вс',
                                ])
                                ->columns(['default' => 2, 'md' => 7])
                                ->columnSpanFull(),
                        ]),
                ]),
                Tab::make('Формула')->schema([
                    Section::make('Расчёт стоимости')
                        ->columns(['default' => 1, 'md' => 2])
                        ->schema([
                            Select::make('operation')->label('Формула')
                                ->options([
                                    'percent_discount' => 'Скидка, %',
                                    'fixed_discount' => 'Скидка, сумма',
                                    'percent_surcharge' => 'Наценка, %',
                                    'fixed_surcharge' => 'Наценка, сумма',
                                    'fixed_total' => 'Фиксированная итоговая цена',
                                    'minimum_total' => 'Минимальная итоговая цена',
                                ])
                                ->default('percent_discount')->required(),
                            TextInput::make('value_text')->label('Значение (% или руб.)')
                                ->required()->maxLength(32),
                        ]),
                ]),
            ])->contained(false)->columnSpanFull(),
        ];
    }

    /** @return array<string, mixed> */
    private function editState(VenuePricingRule $record): array
    {
        $conditions = $record->conditions ?? [];
        $percentage = in_array($record->operation, ['percent_discount', 'percent_surcharge'], true);

        return [
            'name' => $record->name,
            'description' => $record->description,
            'venue_court_id' => $record->venue_court_id,
            'priority' => $record->priority,
            'is_enabled' => $record->is_enabled,
            'stop_processing' => $record->stop_processing,
            'scope' => $conditions['scope'] ?? 'all',
            'min_duration_minutes' => $conditions['min_duration_minutes'] ?? null,
            'max_duration_minutes' => $conditions['max_duration_minutes'] ?? null,
            'min_steps' => $conditions['min_steps'] ?? null,
            'weekdays' => $conditions['weekdays'] ?? [],
            'date_from' => $conditions['date_from'] ?? null,
            'date_to' => $conditions['date_to'] ?? null,
            'time_from' => $conditions['time_from'] ?? null,
            'time_to' => $conditions['time_to'] ?? null,
            'min_base_minor' => $conditions['min_base_minor'] ?? null,
            'operation' => $record->operation,
            'value_text' => $percentage
                ? number_format((int) $record->value / 100, 2, '.', '')
                : app(MinorAmountParser::class)->format(
                    (int) $record->value, $this->getOwnerRecord()->bookingPolicyCurrency(),
                ),
        ];
    }

    private function saveRule(array $data, ?VenuePricingRule $source = null): void
    {
        abort_unless($this->canManagePricingRules(), 403);
        if ($source !== null) {
            // An existing rule's scope cannot change between published revisions.
            $data['venue_court_id'] = $source->venue_court_id;
        }
        app(VenuePricingRuleManager::class)->saveDraft(
            $this->getOwnerRecord(), auth('web')->user(), $data, $source,
        );
        Notification::make()->success()->title('Черновик тарифа сохранён')->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->whereIn('status', ['draft', 'published'])->orderBy('priority')->orderByDesc('version'))
            ->columns([
                TextColumn::make('name')->label('Условие')->searchable(),
                TextColumn::make('court.name')->label('Зал')->placeholder('Все залы'),
                TextColumn::make('status')->label('Статус')->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'draft' ? 'Черновик' : 'Опубликовано'),
                IconColumn::make('is_enabled')->label('Активно')->boolean(),
                TextColumn::make('operation')->label('Формула')->formatStateUsing(fn (string $state): string => match ($state) {
                    'percent_discount' => 'Скидка, %',
                    'fixed_discount' => 'Скидка, сумма',
                    'percent_surcharge' => 'Наценка, %',
                    'fixed_surcharge' => 'Наценка, сумма',
                    'fixed_total' => 'Итоговая цена',
                    'minimum_total' => 'Минимальная цена',
                    default => $state,
                }),
                TextColumn::make('priority')->label('Приоритет')->numeric(),
                TextColumn::make('version')->label('Версия')->numeric(),
            ])
            ->emptyStateHeading('Тарифные условия ещё не добавлены')
            ->headerActions([
                Action::make('managePricingRules')->label('Настроить тарифы')
                    ->icon('heroicon-o-calculator')
                    ->visible(fn (): bool => $this->canManagePricingRules())
                    ->modalHeading('Новое тарифное условие')
                    ->modalWidth(Width::SixExtraLarge)
                    ->stickyModalHeader()->stickyModalFooter()
                    ->schema($this->pricingRuleForm())
                    ->modalSubmitActionLabel('Сохранить черновик')
                    ->action(fn (array $data) => $this->saveRule($data)),
                Action::make('previewPricingRule')->label('Проверить стоимость')
                    ->icon('heroicon-o-eye')
                    ->visible(fn (): bool => $this->canManagePricingRules())
                    ->modalHeading('Проверка тарифа')
                    ->modalWidth(Width::Large)
                    ->schema([
                        Select::make('venue_court_id')->label('Зал')->required()
                            ->options(fn (): array => $this->getOwnerRecord()->courts()
                                ->pluck('name', 'id')->all()),
                        DatePicker::make('date')->label('Дата')->required()->default(now()->addDay()),
                        TimePicker::make('time')->label('Время')->seconds(false)->required()->default('12:00'),
                        TextInput::make('duration_minutes')->label('Длительность, мин')
                            ->numeric()->integer()->required()->minValue(15)->maxValue(1440)->default(120),
                        Select::make('scope')->label('Формат')
                            ->options([
                                'whole' => 'Весь зал',
                                'half_a' => 'Половина А',
                                'half_b' => 'Половина Б',
                            ])
                            ->required()->default('whole'),
                    ])
                    ->modalSubmitActionLabel('Рассчитать')
                    ->action(function (array $data): void {
                        $this->previewPrice($data);
                    }),
            ])
            ->recordActions([
                Action::make('editPricingRule')->label('Редактировать')
                    ->visible(fn (): bool => $this->canManagePricingRules())
                    ->modalHeading(fn (VenuePricingRule $record): string =>
                        $record->status === 'draft' ? 'Редактировать черновик' : 'Новая редакция условия')
                    ->modalWidth(Width::SixExtraLarge)
                    ->stickyModalHeader()->stickyModalFooter()
                    ->schema($this->pricingRuleForm())
                    ->fillForm(fn (VenuePricingRule $record): array => $this->editState($record))
                    ->modalSubmitActionLabel('Сохранить черновик')
                    ->action(fn (VenuePricingRule $record, array $data) => $this->saveRule($data, $record)),
                Action::make('publishPricingRule')->label('Опубликовать')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (VenuePricingRule $record): bool => $this->canManagePricingRules() && $record->status === 'draft')
                    ->requiresConfirmation()
                    ->action(function (VenuePricingRule $record): void {
                        abort_unless($this->canManagePricingRules(), 403);
                        app(VenuePricingRuleManager::class)->publish(
                            $this->getOwnerRecord(), auth('web')->user(), $record,
                        );
                        Notification::make()->success()->title('Тариф опубликован')->send();
                    }),
                Action::make('excludeRule')->label('Исключения по залам')
                    ->visible(fn (VenuePricingRule $record): bool => $this->canManagePricingRules()
                        && $record->status === 'published' && $record->venue_court_id === null)
                    ->modalHeading('Наследование условия залами')
                    ->modalWidth(Width::Large)
                    ->schema([
                        CheckboxList::make('excluded_court_ids')
                            ->label('Не применять к этим залам')
                            ->options(fn (): array => $this->getOwnerRecord()->courts()
                                ->pluck('name', 'id')->all())
                            ->columns(1),
                    ])
                    ->fillForm(fn (VenuePricingRule $record): array => [
                        'excluded_court_ids' => $this->getOwnerRecord()->courtPricingRuleOverrides()
                            ->where('rule_key', $record->rule_key)->where('is_excluded', true)
                            ->pluck('venue_court_id')->map(fn ($id): string => (string) $id)->all(),
                    ])
                    ->action(function (VenuePricingRule $record, array $data): void {
                        abort_unless($this->canManagePricingRules(), 403);
                        $excluded = array_map('intval', $data['excluded_court_ids'] ?? []);
                        DB::transaction(function () use ($record, $excluded): void {
                            foreach ($this->getOwnerRecord()->courts as $court) {
                                app(VenuePricingRuleManager::class)->excludeForCourt(
                                    $this->getOwnerRecord(), auth('web')->user(), $court,
                                    $record->rule_key, in_array($court->id, $excluded, true),
                                );
                            }
                        });
                        Notification::make()->success()->title('Наследование обновлено')->send();
                    }),
                Action::make('deleteDraft')->label('Удалить черновик')
                    ->icon('heroicon-o-trash')->color('danger')
                    ->visible(fn (VenuePricingRule $record): bool => $this->canManagePricingRules() && $record->status === 'draft')
                    ->requiresConfirmation()
                    ->action(function (VenuePricingRule $record): void {
                        abort_unless($this->canManagePricingRules(), 403);
                        app(VenuePricingRuleManager::class)->deleteDraft(
                            $this->getOwnerRecord(), auth('web')->user(), $record,
                        );
                        Notification::make()->success()->title('Черновик удалён')->send();
                    }),
            ]);
    }

    /** @param array<string, mixed> $data */
    private function previewPrice(array $data): void
    {
        abort_unless($this->canManagePricingRules(), 403);
        /** @var Venue $venue */
        $venue = $this->getOwnerRecord();
        $court = $venue->courts()->findOrFail((int) $data['venue_court_id']);
        $user = auth('web')->user();
        abort_unless(app(VenueCommercialAccess::class)->allowsForCourt(
            $user, $court, VenuePermissionEnum::MANAGE_BOOKING_POLICY,
        ), 403);

        $policy = VenueBookingPolicy::query()->where('venue_id', $venue->id)
            ->where('active_marker', true)->first();
        if (! $policy) {
            throw ValidationException::withMessages(['venue_court_id' => 'Сначала опубликуйте базовые условия аренды.']);
        }

        $effective = app(EffectiveCourtPolicy::class)->resolve($venue, $court, $policy);
        $step = (int) $effective['time_step_minutes'];
        $duration = (int) $data['duration_minutes'];
        $timezone = $venue->schedule()->value('timezone') ?: 'Europe/Moscow';
        $start = CarbonImmutable::parse($data['date'].' '.$data['time'], $timezone);
        if ($step < 1 || $duration % $step !== 0
            || $duration < (int) $effective['minimum_duration_minutes']
            || $duration > (int) $effective['maximum_duration_minutes']
            || ($start->hour * 60 + $start->minute) % $step !== 0) {
            throw ValidationException::withMessages(['duration_minutes' => 'Проверьте длительность и шаг аренды.']);
        }
        $scope = VenueBookingScopeEnum::from($data['scope']);
        if ($scope === VenueBookingScopeEnum::WHOLE ? ! $court->allows_whole
            : (! $court->supports_halves || ! $court->allows_halves)) {
            throw ValidationException::withMessages(['scope' => 'Этот формат аренды недоступен для зала.']);
        }
        $price = app(VenueSlotPricing::class)->calculate(
            $venue, $policy, $start, $duration, $scope, $court, $effective,
        );
        $result = app(VenuePricingRuleEvaluator::class)->evaluate(
            $venue, $court, $start, $duration, $step, $scope, $price['amount_minor'],
        );
        $money = app(MinorAmountParser::class);
        $currency = $policy->currency;
        $commission = app(VenueCommissionCalculator::class)->calculate(
            $venue, $court, $result['total_minor'], $currency,
        );
        $base = $money->format($result['base_minor'], $currency).' '.$currency;
        $total = $money->format($commission['total_minor'], $currency).' '.$currency;
        $portalFee = $money->format($commission['portal_fee_minor'], $currency);
        $agentFee = $money->format($commission['agent_fee_minor'], $currency);
        $afterRules = $money->format($result['total_minor'], $currency);
        $adjustments = collect($result['adjustments'])->map(
            fn (array $row): string => $row['name'].' (v'.$row['version'].')',
        )->implode(', ');

        Notification::make()->success()
            ->title('Стоимость: '.$total)
            ->body('Базовая: '.$base . ($adjustments !== '' ? '. Условия: '.$adjustments : '. Условия не применены.')
                .'. После скидок: '.$afterRules.'. Комиссия MSKBA: '.$portalFee
                .'. Комиссия агента: '.$agentFee.' '.$currency)
            ->persistent()->send();
    }
}
