@php
    $title = 'Залы площадки';
    $policy = \App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy::query()
        ->where('venue_id', $venue->id)->where('active_marker', true)->first();
    $money = app(\App\Modules\VenueBooking\Application\Services\MinorAmountParser::class);
    $currency = $policy?->currency ?? 'RUB';
@endphp
@extends('theme::layouts.account', ['title' => $title.' · '.$venue->name])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => $title,
        'subtitle' => $venue->name,
    ])
@endsection

@section('account-content')
<div class="app-rental-pricing">
    <div class="app-rental-pricing__actions">
        @if($policy)
            <a class="button secondary" href="{{ route('account.venues.booking-policy.edit', $venue->id) }}">Условия площадки</a>
        @endif
        <a class="button secondary" href="{{ route('account.venues') }}">Мои площадки</a>
    </div>

    @if(session('status'))<p class="app-rental-pricing__success" role="status">{{ session('status') }}</p>@endif
    @if(session('error'))<p class="app-rental-pricing__error" role="alert">{{ session('error') }}</p>@endif
    @if($errors->any())<p class="app-rental-pricing__error" role="alert">{{ $errors->first() }}</p>@endif

    @foreach($courts as $court)
        @php
            $courtRoute = [$venue->routeIdentifier(), $court->routeIdentifier()];
            $canEditCourt = $canManageCourt[$court->id] ?? false;
            $canEditPolicy = $canManageCourtPolicy[$court->id] ?? false;
        @endphp

        <article class="app-rental-pricing__court" aria-labelledby="court-{{ $court->id }}-title">
            <section class="panel app-rental-pricing__panel">
                <div class="app-rental-pricing__court-heading">
                    <h2 id="court-{{ $court->id }}-title">{{ $court->name }}</h2>
                    @if($court->is_primary)<span class="badge">Основной зал</span>@endif
                </div>

                @if($canEditCourt)
                    <form class="app-rental-pricing__form" method="POST"
                          action="{{ route('account.venues.courts.update', $courtRoute) }}">
                        @csrf @method('PUT')
                        <div class="app-rental-pricing__grid">
                            <label class="field-group" for="court-{{ $court->id }}-name">
                                <span>Название</span>
                                <input id="court-{{ $court->id }}-name" type="text" name="name" maxlength="120" required value="{{ $court->name }}">
                            </label>
                            <label class="field-group" for="court-{{ $court->id }}-alias">
                                <span>Ссылка (alias)</span>
                                <input id="court-{{ $court->id }}-alias" type="text" name="alias" maxlength="120" value="{{ $court->alias }}">
                            </label>
                            <label class="field-group" for="court-{{ $court->id }}-order">
                                <span>Порядок</span>
                                <input id="court-{{ $court->id }}-order" type="number" name="sort_order" min="0" max="65535" required value="{{ $court->sort_order }}">
                            </label>
                            <label class="field-group" for="court-{{ $court->id }}-hoops">
                                <span>Количество колец</span>
                                <select name="hoops_count" id="court-{{ $court->id }}-hoops">
                                    @foreach([1,2] as $hoops)
                                        <option value="{{ $hoops }}" @selected((int) $court->hoops_count === $hoops)>{{ $hoops }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="field-group" for="court-{{ $court->id }}-surface">
                                <span>Покрытие</span>
                                <select id="court-{{ $court->id }}-surface" name="surface_type">
                                    <option value="">Не указано</option>
                                    @foreach($surfaceTypes as $surface)
                                        <option value="{{ $surface->value }}" @selected($court->surface_type?->value === $surface->value)>{{ $surface->label() }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="field-group" for="court-{{ $court->id }}-environment">
                                <span>Тип зала</span>
                                <select id="court-{{ $court->id }}-environment" name="environment">
                                    <option value="">Не указано</option>
                                    @foreach($courtEnvironments as $environment)
                                        <option value="{{ $environment->value }}" @selected($court->environment?->value === $environment->value)>{{ $environment->label() }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                        <label class="app-rental-pricing__check">
                            <input type="hidden" name="allows_whole" value="0">
                            <input type="checkbox" name="allows_whole" value="1" @checked($court->allows_whole)>
                            <span>Разрешать аренду всего зала</span>
                        </label>
                        <label class="app-rental-pricing__check">
                            <input type="hidden" name="allows_halves" value="0">
                            <input type="checkbox" name="allows_halves" value="1" @checked($court->allows_halves)>
                            <span>Разрешать аренду половин</span>
                        </label>
                        <div class="app-rental-pricing__actions">
                            <button class="button primary" type="submit">Сохранить зал</button>
                        </div>
                    </form>
                @endif

                @if($canManageVenue && !$court->is_primary)
                    <form method="POST" action="{{ route('account.venues.courts.primary', $courtRoute) }}">
                        @csrf
                        <button class="button secondary" type="submit">Сделать основным</button>
                    </form>
                @endif
            </section>

            @if($canEditPolicy && $policy)
                <section class="panel app-rental-pricing__panel" aria-labelledby="court-{{ $court->id }}-policy">
                    <h2 id="court-{{ $court->id }}-policy">Условия аренды · {{ $court->name }}</h2>
                    <form class="app-rental-pricing__form" method="POST" action="{{ route('account.venues.courts.settings.update', $courtRoute) }}">
                        @csrf @method('PUT')
                        <div class="app-rental-pricing__grid">
                            @foreach([
                                'operational_status' => ['Доступность', [
                                    'inherit' => 'Наследовать',
                                    'active' => 'Активен',
                                    'temporarily_closed' => 'Временно закрыт',
                                ]],
                                'requires_payment' => ['Требуется оплата', [
                                    'inherit' => 'Наследовать',
                                    'yes' => 'Да',
                                    'no' => 'Нет',
                                ]],
                                'requires_booking' => ['Требуется бронирование', [
                                    'inherit' => 'Наследовать',
                                    'yes' => 'Да',
                                    'no' => 'Нет',
                                ]],
                                'requires_booking_approval' => ['Подтверждать заявку', [
                                    'inherit' => 'Наследовать',
                                    'yes' => 'Да',
                                    'no' => 'Нет',
                                ]],
                                'rental_enabled' => ['Принимать заявки', [
                                    'inherit' => 'Наследовать',
                                    'yes' => 'Да',
                                    'no' => 'Нет',
                                ]],
                            ] as $setting => [$label, $options])
                                @php
                                    $value = $court->{$setting};
                                    $selected = $value === null ? 'inherit' : (
                                        is_bool($value) ? ($value ? 'yes' : 'no') : (
                                            $value instanceof \BackedEnum ? $value->value : (string) $value
                                        )
                                    );
                                @endphp
                                <label class="field-group" for="court-{{ $court->id }}-{{ $setting }}">
                                    <span>{{ $label }}</span>
                                    <select id="court-{{ $court->id }}-{{ $setting }}" name="{{ $setting }}">
                                        @foreach($options as $optionValue => $text)
                                            <option value="{{ $optionValue }}" @selected($selected === $optionValue)>{{ $text }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @endforeach
                            @foreach([
                                'minimum_duration_minutes' => ['Минимальная аренда, мин', $policy->minimum_duration_minutes],
                                'maximum_duration_minutes' => ['Максимальная аренда, мин', $policy->maximum_duration_minutes],
                                'time_step_minutes' => ['Шаг времени, мин', $policy->time_step_minutes],
                                'minimum_lead_time_minutes' => ['Минимум до начала, мин', $policy->minimum_lead_time_minutes],
                                'maximum_advance_days' => ['Горизонт бронирования, дней', $policy->maximum_advance_days],
                            ] as $key => [$label, $default])
                                <label class="field-group" for="court-{{ $court->id }}-{{ $key }}">
                                    <span>{{ $label }}</span>
                                    <input id="court-{{ $court->id }}-{{ $key }}" type="number" min="0"
                                        name="{{ $key }}" value="{{ $court->{$key} ?? '' }}" placeholder="От площадки: {{ $default }}">
                                </label>
                            @endforeach
                            @foreach(['whole' => 'Весь зал', 'half' => 'Половина'] as $scope => $label)
                                @php($column = $scope.'_price_per_step_minor')
                                <label class="field-group" for="court-{{ $court->id }}-{{ $scope }}-price">
                                    <span>{{ $label }}, {{ $currency }} / шаг</span>
                                    <input id="court-{{ $court->id }}-{{ $scope }}-price" type="text"
                                        name="{{ $scope }}_price_per_step" inputmode="decimal"
                                        value="{{ $court->{$column} === null ? '' : $money->format((int) $court->{$column}, $currency) }}"
                                        placeholder="Наследовать">
                                </label>
                            @endforeach
                        </div>
                        <div class="app-rental-pricing__actions">
                            <button class="button primary" type="submit">Сохранить условия</button>
                        </div>
                    </form>
                </section>

                <section class="panel app-rental-pricing__panel" aria-labelledby="court-{{ $court->id }}-weekly">
                    <h2 id="court-{{ $court->id }}-weekly">Цены по дням недели</h2>
                    @if($court->slotPrices()->exists())
                        <div class="app-rental-pricing__table-wrap">
                            <table class="app-rental-pricing__table">
                                <thead><tr><th>День</th><th>Время</th><th>Весь зал</th><th>Половина</th><th><span class="sr-only">Действия</span></th></tr></thead>
                                <tbody>
                                    @foreach($court->slotPrices()->orderBy('day_of_week')->orderBy('starts_at')->get() as $weekPrice)
                                        <tr>
                                            <td>{{ ['','Пн','Вт','Ср','Чт','Пт','Сб','Вс'][$weekPrice->day_of_week] ?? '—' }}</td>
                                            <td>{{ substr((string) $weekPrice->starts_at, 0, 5) }}</td>
                                            <td>{{ $weekPrice->whole_price_per_step_minor === null ? 'Наследуется' : $money->format((int) $weekPrice->whole_price_per_step_minor, $currency) }}</td>
                                            <td>{{ $weekPrice->half_price_per_step_minor === null ? 'Наследуется' : $money->format((int) $weekPrice->half_price_per_step_minor, $currency) }}</td>
                                            <td><form method="POST" action="{{ route('account.venues.courts.prices.destroy', [$venue->routeIdentifier(), $court->routeIdentifier(), $weekPrice->id]) }}">
                                                @csrf @method('DELETE')<button type="submit" class="button secondary">Удалить</button>
                                            </form></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    <form class="app-rental-pricing__form" method="POST" action="{{ route('account.venues.courts.prices.store', $courtRoute) }}">
                        @csrf
                        <div class="app-rental-pricing__grid">
                            <label class="field-group" for="court-{{ $court->id }}-weekday">
                                <span>День недели</span>
                                <select id="court-{{ $court->id }}-weekday" name="day_of_week" required>
                                    @foreach(['Пн','Вт','Ср','Чт','Пт','Сб','Вс'] as $dayIndex => $day)
                                        <option value="{{ $dayIndex+1 }}">{{ $day }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="field-group" for="court-{{ $court->id }}-weekly-time">
                                <span>Время</span><input id="court-{{ $court->id }}-weekly-time" type="time" name="starts_at" required>
                            </label>
                            @foreach(['whole' => 'Весь зал', 'half' => 'Половина'] as $scope => $label)
                                <label class="field-group" for="court-{{ $court->id }}-weekly-{{ $scope }}">
                                    <span>{{ $label }}, {{ $currency }}</span>
                                    <input id="court-{{ $court->id }}-weekly-{{ $scope }}" type="text"
                                        inputmode="decimal" name="{{ $scope }}_price_per_step" placeholder="Наследовать">
                                </label>
                            @endforeach
                        </div>
                        <button class="button primary" type="submit">Сохранить недельную цену</button>
                    </form>
                </section>

                @include('theme::pages.venues.partials.calendar-price-editor', [
                    'calendarPrices' => $court->calendarSlotPrices()->orderBy('date')->orderBy('starts_at')->get(),
                    'calendarStoreUrl' => route('account.venues.courts.calendar-prices.store', $courtRoute),
                    'calendarDeleteUrl' => route('account.venues.courts.calendar-prices.destroy', [$venue->routeIdentifier(), $court->routeIdentifier(), '__PRICE__']),
                    'calendarStep' => $court->time_step_minutes ?? $policy->time_step_minutes,
                    'calendarCurrency' => $currency,
                    'calendarId' => 'court-'.$court->id.'-calendar',
                ])
            @endif
        </article>
    @endforeach

    @if($canManageVenue)
        <section class="panel app-rental-pricing__panel" aria-labelledby="create-court-heading">
            <h2 id="create-court-heading">Добавить зал</h2>
            <form class="app-rental-pricing__form" method="POST" action="{{ route('account.venues.courts.store', $venue->routeIdentifier()) }}">
                @csrf
                <div class="app-rental-pricing__grid">
                    <label class="field-group" for="new-court-name">
                        <span>Название</span><input id="new-court-name" type="text" name="name" maxlength="120" required>
                    </label>
                    <label class="field-group" for="new-court-hoops">
                        <span>Количество колец</span><select id="new-court-hoops" name="hoops_count"><option value="1">1</option><option value="2" selected>2</option></select>
                    </label>
                    <label class="field-group" for="new-court-surface">
                        <span>Покрытие</span><select id="new-court-surface" name="surface_type"><option value="">Не указано</option>
                            @foreach($surfaceTypes as $surface)<option value="{{ $surface->value }}">{{ $surface->label() }}</option>@endforeach
                        </select>
                    </label>
                </div>
                <label class="app-rental-pricing__check"><input type="checkbox" name="allows_whole" value="1" checked>Разрешать аренду целиком</label>
                <label class="app-rental-pricing__check"><input type="checkbox" name="allows_halves" value="1">Разрешать аренду половин</label>
                <button class="button primary" type="submit">Добавить зал</button>
            </form>
        </section>
    @endif
</div>
@endsection
