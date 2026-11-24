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
    $editor = app(\App\Modules\Venue\Application\Services\AccountVenueEditorAccess::class)
        ->capabilities(auth()->user(), $venue);
    $breadcrumbs = [
        ['label' => 'Аккаунт', 'url' => route('account')],
        ['label' => 'Мои контракты', 'url' => route('account.contracts')],
        ['label' => 'Мои площадки', 'url' => route('account.contracts.venues')],
        ['label' => $venue->name, 'url' => route('account.venues.edit', ['alias' => $venue->routeIdentifier(), 'tab' => 'rental'])],
        ['label' => 'Условия аренды'],
    ];
@endphp
@extends('theme::layouts.account', ['title' => $title.' · '.$venue->name, 'breadcrumbs' => $breadcrumbs])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => $venue->name,
        'subtitle' => $title,
    ])
@endsection

@section('account-content')
    @if(app(\App\Modules\VenueBooking\Application\Services\VenueSpecialTermsManager::class)->canManage(auth()->user(), $venue))
        <p><a href="{{ route('account.venues.special-terms.index', $venue->id) }}">Специальные условия</a></p>
    @endif
<div class="app-venue-editor" data-venue-editor>
    @include('theme::pages.venues.partials.editor-tabs', ['activeTab' => 'rental', 'editor' => $editor])
    <div class="app-rental-pricing">
    <div class="app-rental-pricing__actions">
        <a class="button secondary" href="{{ route('account.venues.courts.index', $venue->routeIdentifier()) }}">Залы</a>
        <a class="button secondary" href="{{ route('account.venues.pricing-rules.index', $venue->id) }}">Тарифные условия</a>
        @if($policy)<a class="button secondary" href="{{ route('account.venues.edit', ['alias' => $venue->routeIdentifier(), 'tab' => 'schedule']).'#venue-tariff-panel-venue' }}">Тарифная сетка по времени</a>@endif
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
                    'hold_duration_minutes' => ['Максимальный срок удержания слота, мин', 30, 1, 1440],
                    'maximum_hold_extension_minutes' => ['Продление удержания, мин', 30, 1, 1440],
                    'payment_window_minutes' => ['Окно оплаты покупателем, мин', 30, 1, 1440],
                    'quote_validity_minutes' => ['Срок действия расчёта цены, мин', 15, 1, 120],
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
                'external_hold_required' => ['Холд требует подтверждения реальной площадкой / агрегатором', false],
                'buyer_postpay_allowed' => ['Разрешить постоплату покупателя', false],
                'agent_portal_postpay_allowed' => ['Разрешить постоплату агента перед MSKBA', false],
                'portal_venue_postpay_allowed' => ['Разрешить постоплату MSKBA площадке', false],
                'agent_portal_self_attestation_allowed' => ['Разрешить доверенное подтверждение агента перед MSKBA', false],
            ] as $name => [$label, $default])
                <label class="check-label">
                    <input type="hidden" name="{{ $name }}" value="0">
                    <input type="checkbox" name="{{ $name }}" value="1" @checked((bool) $field($name, $default))>
                    <span>{{ $label }}</span>
                </label>
            @endforeach
            <div class="app-rental-pricing__grid">
                <label class="field-group">Источник сведений о максимальном сроке холда
                    <select name="hold_verification_method">
                        <option value="">Не уточнено</option>
                        @foreach(['phone'=>'Телефон', 'messenger'=>'Мессенджер', 'provider'=>'Агрегатор', 'contract'=>'Контракт', 'other'=>'Другое'] as $value=>$label)
                            <option value="{{ $value }}" @selected($field('hold_verification_method') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field-group">Кто подтвердил и когда
                    <textarea name="hold_verification_note" maxlength="2000" rows="2">{{ $field('hold_verification_note') }}</textarea>
                </label>
            </div>
            @if($policy?->hold_verified_at)
                <p>Последняя проверка: {{ $policy->hold_verified_at->format('d.m.Y H:i') }} · значение: {{ $policy->hold_duration_minutes }} мин</p>
            @endif
            <p><a href="{{ route('account.venues.invoice-templates.index', $venue->id) }}">Шаблоны счетов аренды</a></p>
            <div class="app-rental-pricing__actions"><button class="button primary" type="submit">Опубликовать условия</button></div>
        </form>
    </section>


</div>
</div>
@endsection
