@php
    $title = 'Условия аренды';
    $field = static fn (string $name, mixed $default = null): mixed => old($name, $policy?->{$name} ?? $default);
    $currency = strtoupper((string) $field('currency', 'RUB'));
    $money = app(\App\Modules\VenueBooking\Application\Services\MinorAmountParser::class);
    $price = static function (string $name) use ($policy, $currency, $money): string {
        if (session()->hasOldInput($name)) return (string) old($name);
        $raw = $policy?->{$name.'_minor'};
        return $raw === null ? '' : $money->format((int) $raw, $currency);
    };
    $canHalves = (int) ($venue->characteristics?->hoops_count ?? 0) >= 2;
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
        <a class="button secondary" href="{{ route('account.venues.courts.index', $venue->routeIdentifier()) }}">Залы</a>
        <a class="button secondary" href="{{ route('account.venues.pricing-rules.index', $venue->id) }}">Тарифные условия</a>
        <a class="button secondary" href="{{ route('account.venues') }}">Мои площадки</a>
    </div>
    @if(session('status'))<p class="app-rental-pricing__success" role="status">{{ session('status') }}</p>@endif
    @if(session('error'))<p class="app-rental-pricing__error" role="alert">{{ session('error') }}</p>@endif
    @if($errors->any())<p class="app-rental-pricing__error" role="alert">{{ $errors->first() }}</p>@endif

    <section class="panel app-rental-pricing__panel" aria-labelledby="venue-policy-heading">
        <h2 id="venue-policy-heading">Базовые условия площадки</h2>
        @if($policy)<p class="app-rental-pricing__subtle">Версия №{{ $policy->version }} · {{ $policy->published_at?->format('d.m.Y H:i') }}</p>@endif
        <form class="app-rental-pricing__form" method="POST" action="{{ route('account.venues.booking-policy.update', $venue->id) }}">
            @csrf @method('PUT')
            @foreach([
                'is_enabled' => ['Принимать заявки на аренду', true],
                'allows_whole' => ['Разрешать аренду всего зала при создании', true],
                'allows_halves' => ['Разрешать аренду половин при создании', $canHalves],
            ] as $name => [$label, $default])
                <label class="check-label">
                    <input type="hidden" name="{{ $name }}" value="0">
                    <input type="checkbox" name="{{ $name }}" value="1" @checked((bool) $field($name, $default)) @disabled($name === 'allows_halves' && ! $canHalves)>
                    <span>{{ $label }}</span>
                </label>
            @endforeach

            <div class="app-rental-pricing__grid">
                @foreach([
                    'minimum_duration_minutes' => ['Минимальная длительность, мин', 60, 15, 1440],
                    'maximum_duration_minutes' => ['Максимальная длительность, мин', 180, 15, 1440],
                    'time_step_minutes' => ['Шаг аренды, мин', 30, 5, 240],
                    'minimum_lead_time_minutes' => ['Минимум до начала, мин', 120, 0, 525600],
                    'maximum_advance_days' => ['На сколько дней вперёд', 90, 1, 730],
                    'hold_duration_minutes' => ['Срок удержания слота, мин', 30, 1, 1440],
                    'maximum_hold_extension_minutes' => ['Продление удержания, мин', 30, 1, 1440],
                    'payment_window_minutes' => ['Окно оплаты, мин', 30, 1, 1440],
                    'quote_validity_minutes' => ['Срок расчёта, мин', 15, 1, 120],
                    'cancellation_before_minutes' => ['Отмена не позднее, мин', null, 0, 525600],
                ] as $key => [$label, $default, $min, $max])
                    <label class="field-group" for="venue-policy-{{ $key }}">
                        <span>{{ $label }}</span>
                        <input id="venue-policy-{{ $key }}" name="{{ $key }}" type="number"
                            min="{{ $min }}" max="{{ $max }}"
                            value="{{ $field($key, $default) }}"
                            @if(!in_array($key, ['cancellation_before_minutes','maximum_hold_extension_minutes','payment_window_minutes'], true)) required @endif>
                    </label>
                @endforeach
                <label class="field-group" for="venue-policy-currency">
                    <span>Валюта ISO</span>
                    <input id="venue-policy-currency" name="currency" maxlength="3" minlength="3" required value="{{ $currency }}">
                </label>
                <label class="field-group" for="venue-policy-whole-price">
                    <span>Весь зал, цена за шаг</span>
                    <input id="venue-policy-whole-price" name="whole_price_per_step" inputmode="decimal" required value="{{ $price('whole_price_per_step') }}">
                </label>
                <label class="field-group" for="venue-policy-half-price">
                    <span>Половина, цена за шаг</span>
                    <input id="venue-policy-half-price" name="half_price_per_step" inputmode="decimal" value="{{ $price('half_price_per_step') }}">
                </label>
            </div>
            @foreach([
                'requires_payment' => ['Требуется оплата', true],
                'allows_hold_extension' => ['Разрешить продление удержания', false],
            ] as $name => [$label, $default])
                <label class="check-label">
                    <input type="hidden" name="{{ $name }}" value="0">
                    <input type="checkbox" name="{{ $name }}" value="1" @checked((bool) $field($name, $default))>
                    <span>{{ $label }}</span>
                </label>
            @endforeach
            <div class="app-rental-pricing__actions"><button class="button primary" type="submit">Опубликовать условия</button></div>
        </form>
    </section>

    @if($policy)
        @include('theme::pages.venues.partials.calendar-price-editor', [
            'calendarPrices' => $venue->calendarSlotPrices()->orderBy('date')->orderBy('starts_at')->get(),
            'calendarStoreUrl' => route('account.venues.calendar-prices.store', $venue->id),
            'calendarDeleteUrl' => route('account.venues.calendar-prices.destroy', [$venue->id, '__PRICE__']),
            'calendarStep' => $policy->time_step_minutes,
            'calendarCurrency' => $policy->currency,
            'calendarId' => 'venue-calendar',
        ])
    @endif
</div>
@endsection
