@php
    $title = 'Аренда · '.$venue->name;
    $formatter = app(\App\Modules\VenueBooking\Application\Services\MinorAmountParser::class);
@endphp
@extends('theme::layouts.app', ['title' => $title])

@section('content')
<section class="container app-rental-pricing app-rental-pricing__public" aria-labelledby="rental-quote-heading">
    <header class="app-account-overview__intro">
        <h1 id="rental-quote-heading">Аренда зала</h1>
        <p>{{ $venue->name }}</p>
    </header>

    @if(isset($error))
        <p class="app-rental-pricing__error" role="alert">{{ $error['message'] }}</p>
    @endif
    @if($errors->any())<p class="app-rental-pricing__error" role="alert">{{ $errors->first() }}</p>@endif

    <section class="panel app-rental-pricing__panel" aria-labelledby="rental-quote-form-heading">
        <h2 id="rental-quote-form-heading">Параметры аренды</h2>
        <form method="POST" action="{{ route('venues.rental.quote', $venue->id) }}" class="app-rental-pricing__form" data-venue-rental-form>
            @csrf
            <div class="app-rental-pricing__grid">
                <label class="field-group" for="rental-court">
                    <span>Зал</span>
                    @if(($courts ?? collect())->count() > 1)
                        <select id="rental-court" name="venue_court_id" required data-rental-court>
                            @foreach($courts as $option)
                                <option value="{{ $option->id }}" @selected((int) old('venue_court_id', $court->id) === (int) $option->id)
                                    data-min="{{ $option->minimum_duration_minutes ?? $policy->minimum_duration_minutes }}"
                                    data-max="{{ $option->maximum_duration_minutes ?? $policy->maximum_duration_minutes }}"
                                    data-step="{{ $option->time_step_minutes ?? $policy->time_step_minutes }}"
                                    data-whole="{{ $option->allows_whole ? 1 : 0 }}"
                                    data-halves="{{ $option->supports_halves && $option->allows_halves ? 1 : 0 }}">
                                    {{ $option->name }}
                                </option>
                            @endforeach
                        </select>
                    @else
                        <input type="hidden" name="venue_court_id" value="{{ $court->id }}">
                        <span>{{ $court->name }}</span>
                    @endif
                </label>
                <label class="field-group" for="rental-starts">
                    <span>Начало</span>
                    <input id="rental-starts" name="starts_at" type="datetime-local" required data-rental-starts
                        step="{{ (int) ($court->time_step_minutes ?? $policy->time_step_minutes) * 60 }}" value="{{ old('starts_at') }}">
                </label>
                <label class="field-group" for="rental-duration">
                    <span>Длительность, мин</span>
                    <input id="rental-duration" name="duration_minutes" type="number" required data-rental-duration
                        min="{{ $court->minimum_duration_minutes ?? $policy->minimum_duration_minutes }}"
                        max="{{ $court->maximum_duration_minutes ?? $policy->maximum_duration_minutes }}"
                        step="{{ $court->time_step_minutes ?? $policy->time_step_minutes }}"
                        value="{{ old('duration_minutes', $court->minimum_duration_minutes ?? $policy->minimum_duration_minutes) }}">
                </label>
                <label class="field-group" for="rental-scope">
                    <span>Область аренды</span>
                    <select id="rental-scope" name="scope" required data-rental-scope>
                        <option value="whole" data-scope="whole" @if(!$court->allows_whole) disabled hidden @endif>Весь зал</option>
                        <option value="half_a" data-scope="half" @if(!$court->supports_halves || !$court->allows_halves) disabled hidden @endif>Половина А</option>
                        <option value="half_b" data-scope="half" @if(!$court->supports_halves || !$court->allows_halves) disabled hidden @endif>Половина Б</option>
                    </select>
                </label>
            </div>
            <div class="app-rental-pricing__actions">
                <button class="button primary" type="submit" data-rental-submit>Рассчитать</button>
                <a class="button secondary" href="{{ route('venues.show', $venue->routeIdentifier()) }}">К площадке</a>
            </div>
        </form>
    </section>

    @if($quote)
        <section class="panel app-rental-pricing__panel" aria-labelledby="rental-quote-result-heading">
            <h2 id="rental-quote-result-heading">Стоимость аренды</h2>
            <p><strong>{{ $court->name }}</strong> · {{ $quote->startsAt->setTimezone($venue->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow'))->format('d.m.Y H:i') }}–{{ $quote->endsAt->setTimezone($venue->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow'))->format('H:i') }}</p>
            @if($quote->commissionBreakdown)
                <div class="app-rental-pricing__section" aria-label="Расшифровка стоимости">
                    <div class="app-rental-pricing__entry">
                        <span>Аренда после скидок</span>
                        <strong>{{ $formatter->format($quote->commissionBreakdown['provider_amount_minor'], $quote->currency) }} {{ $formatter->currencyLabel($quote->currency) }}</strong>
                    </div>
                    <div class="app-rental-pricing__entry">
                        <span>Комиссия MSKBA</span>
                        <strong>{{ $formatter->format($quote->commissionBreakdown['portal_fee_minor'], $quote->currency) }} {{ $formatter->currencyLabel($quote->currency) }}</strong>
                    </div>
                    @if($quote->commissionBreakdown['agent_fee_minor'] > 0)
                        <div class="app-rental-pricing__entry">
                            <span>Комиссия агента</span>
                            <strong>{{ $formatter->format($quote->commissionBreakdown['agent_fee_minor'], $quote->currency) }} {{ $formatter->currencyLabel($quote->currency) }}</strong>
                        </div>
                    @endif
                </div>
            @endif
            <p class="app-rental-pricing__total">Итого: {{ $formatter->format($quote->amountMinor, $quote->currency) }} {{ $formatter->currencyLabel($quote->currency) }}</p>
            @auth
                <form class="app-rental-pricing__form" method="POST" action="{{ route('account.venue-bookings.store') }}">
                    @csrf
                    <input type="hidden" name="quote_id" value="{{ $quote->publicId }}">
                    <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <button class="button primary" type="submit">Отправить заявку</button>
                </form>
            @else
                <a class="button primary" href="{{ route('login') }}">Войти, чтобы отправить заявку</a>
            @endauth
        </section>
    @endif
</section>
<script>
(() => {
    const form = document.querySelector('[data-venue-rental-form]');
    if (!form) return;
    const court = form.querySelector('[data-rental-court]');
    const duration = form.querySelector('[data-rental-duration]');
    const start = form.querySelector('[data-rental-starts]');
    const scope = form.querySelector('[data-rental-scope]');
    const submit = form.querySelector('[data-rental-submit]');
    const initial = {
        min: {{ (int) ($court->minimum_duration_minutes ?? $policy->minimum_duration_minutes) }},
        max: {{ (int) ($court->maximum_duration_minutes ?? $policy->maximum_duration_minutes) }},
        step: {{ (int) ($court->time_step_minutes ?? $policy->time_step_minutes) }},
        whole: {{ $court->allows_whole ? 'true' : 'false' }},
        halves: {{ ($court->supports_halves && $court->allows_halves) ? 'true' : 'false' }},
    };
    function sync() {
        const option = court?.selectedOptions?.[0];
        const min = Number(option?.dataset.min ?? initial.min);
        const max = Number(option?.dataset.max ?? initial.max);
        const step = Number(option?.dataset.step ?? initial.step);
        duration.min = String(min);
        duration.max = String(max);
        duration.step = String(step);
        start.step = String(step * 60);
        const value = Number(duration.value);
        if (!value || value < min || value > max || value % step !== 0) duration.value = String(min);
        const whole = option ? option.dataset.whole === '1' : initial.whole;
        const halves = option ? option.dataset.halves === '1' : initial.halves;
        scope.querySelectorAll('option').forEach(o => {
            const available = o.dataset.scope === 'whole' ? whole : halves;
            o.hidden = !available; o.disabled = !available;
        });
        if (scope.selectedOptions[0]?.disabled) {
            scope.value = Array.from(scope.options).find(o => !o.disabled)?.value ?? '';
        }
        submit.disabled = !Array.from(scope.options).some(o => !o.disabled);
    }
    court?.addEventListener('change', sync);
    sync();
})();
</script>
@endsection
