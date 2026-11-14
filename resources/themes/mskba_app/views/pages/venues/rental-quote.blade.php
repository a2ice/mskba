@php
    $title = 'Аренда · '.$venue->name;
    $formatter = app(\App\Modules\VenueBooking\Application\Services\MinorAmountParser::class);
    $timezone = $venue->schedule()->value('timezone') ?: config('app.timezone', 'Europe/Moscow');
    $quoteStartsLocal = $quote?->startsAt->setTimezone($timezone);
    $quoteEndsLocal = $quote?->endsAt->setTimezone($timezone);
    $quoteDuration = $quote ? (int) $quote->startsAt->diffInMinutes($quote->endsAt) : null;
    $formStarts = old('starts_at', $quoteStartsLocal?->format('Y-m-d\TH:i') ?? '');
    $formDuration = old('duration_minutes', $quoteDuration ?? ($court->minimum_duration_minutes ?? $policy?->minimum_duration_minutes ?? 60));
    $formScope = old('scope', $quote?->scope->value ?? 'whole');
    $formCourt = (int) old('venue_court_id', $court->id);
    $savedQuote = $quote ? \App\Modules\VenueBooking\Domain\Models\VenueBookingQuote::query()
        ->where('public_id', $quote->publicId)->first() : null;
    $discountMinor = max(0, (int) data_get($savedQuote?->snapshot, 'pricing.base_amount_minor', 0)
        - (int) data_get($savedQuote?->snapshot, 'pricing.subtotal_after_rules_minor', 0));
    $venueSlotPromotions = app(\App\Modules\Promotions\Application\Queries\ResolveActivePromotions::class)
        ->forPlacement('venue_slots', (int) $venue->id);
@endphp
@extends('theme::layouts.app', ['title' => $title])

@section('content')
<section class="container app-rental-pricing app-rental-pricing__public" data-rental-quote-page
    data-rental-venue-id="{{ $venue->id }}" data-rental-current-court="{{ $court->id }}"
    data-rental-original-date="{{ $quoteStartsLocal?->toDateString() ?? '' }}"
    aria-labelledby="rental-quote-heading">
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
        <form method="POST" action="{{ route('venues.rental.quote', $venue->id) }}"
            class="app-rental-pricing__form" data-venue-rental-form>
            @csrf
            <div class="app-rental-pricing__grid">
                <label class="field-group app-rental-pricing__field" for="rental-court">
                    <span>Зал</span>
                    <span class="app-rental-pricing__select-wrap">
                        <select id="rental-court" name="venue_court_id" required
                            class="app-rental-pricing__select" data-rental-court>
                            @foreach($courts as $option)
                                <option value="{{ $option->id }}" @selected($formCourt === (int) $option->id)
                                    data-min="{{ $option->minimum_duration_minutes ?? $policy?->minimum_duration_minutes ?? 60 }}"
                                    data-max="{{ $option->maximum_duration_minutes ?? $policy?->maximum_duration_minutes ?? 480 }}"
                                    data-step="{{ $option->time_step_minutes ?? $policy?->time_step_minutes ?? 30 }}"
                                    data-whole="{{ $option->allows_whole ? 1 : 0 }}"
                                    data-halves="{{ $option->supports_halves && $option->allows_halves ? 1 : 0 }}">
                                    {{ $option->publicName() }}
                                </option>
                            @endforeach
                        </select>
                    </span>
                </label>
                <label class="field-group app-rental-pricing__field" for="rental-starts">
                    <span>Начало</span>
                    <input id="rental-starts" name="starts_at" type="datetime-local" required data-rental-starts
                        step="{{ (int) ($court->time_step_minutes ?? $policy?->time_step_minutes ?? 30) * 60 }}"
                        value="{{ $formStarts }}">
                </label>
                <label class="field-group app-rental-pricing__field" for="rental-duration">
                    <span>Длительность, мин</span>
                    <input id="rental-duration" name="duration_minutes" type="number" required data-rental-duration
                        min="{{ $court->minimum_duration_minutes ?? $policy?->minimum_duration_minutes ?? 60 }}"
                        max="{{ $court->maximum_duration_minutes ?? $policy?->maximum_duration_minutes ?? 480 }}"
                        step="{{ $court->time_step_minutes ?? $policy?->time_step_minutes ?? 30 }}"
                        value="{{ $formDuration }}">
                </label>
                <label class="field-group app-rental-pricing__field" for="rental-scope">
                    <span>Область аренды</span>
                    <span class="app-rental-pricing__select-wrap">
                        <select id="rental-scope" name="scope" required class="app-rental-pricing__select" data-rental-scope>
                            <option value="whole" data-scope="whole" @selected($formScope === 'whole')
                                @if(!$court->allows_whole) disabled hidden @endif>Весь зал</option>
                            <option value="half_a" data-scope="half" @selected($formScope === 'half_a')
                                @if(!$court->supports_halves || !$court->allows_halves) disabled hidden @endif>Половина А</option>
                            <option value="half_b" data-scope="half" @selected($formScope === 'half_b')
                                @if(!$court->supports_halves || !$court->allows_halves) disabled hidden @endif>Половина Б</option>
                        </select>
                    </span>
                </label>
            </div>
            <div class="app-rental-pricing__actions">
                <button class="button primary" type="submit" data-rental-submit>Рассчитать</button>
                <button class="button secondary" type="button" data-rental-open-calendar>Выбрать время</button>
                <a class="button secondary" href="{{ route('venues.show', $venue->routeIdentifier()) }}">К площадке</a>
            </div>
        </form>
    </section>

    @if($quote)
        <section class="panel app-rental-pricing__panel" aria-labelledby="rental-quote-result-heading"
            data-rental-quote-monitor data-quote-id="{{ $quote->publicId }}"
            data-quote-status-url="{{ route('venues.rental.quote.status', $venue->id) }}"
            data-quote-expiration="{{ $quote->validUntil->toIso8601String() }}">
            <h2 id="rental-quote-result-heading">Стоимость аренды</h2>
            <p class="app-rental-pricing__summary">
                <strong>{{ $court->publicName() }}</strong> ·
                {{ $quoteStartsLocal->format('d.m.Y H:i') }}–{{ $quoteEndsLocal->format('H:i') }}
            </p>
            @if($discountMinor > 0)
                <p class="app-rental-pricing__discount">
                    <span>Скидка</span>
                    <strong>−{{ $formatter->format($discountMinor, $quote->currency) }} {{ $formatter->currencyLabel($quote->currency) }}</strong>
                </p>
            @endif
            <p class="app-rental-pricing__availability-message" data-rental-live-message aria-live="polite" hidden></p>
            <div class="app-rental-pricing__confirm">
                <p class="app-rental-pricing__total">
                    Итого: {{ $formatter->format($quote->amountMinor, $quote->currency) }}
                    {{ $formatter->currencyLabel($quote->currency) }}
                </p>
                @auth
                    <form method="POST" action="{{ route('account.venue-bookings.store') }}"
                        data-rental-send-form>
                        @csrf
                        <input type="hidden" name="quote_id" value="{{ $quote->publicId }}">
                        <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        <button class="button primary" type="submit" data-rental-send-button>Отправить заявку</button>
                    </form>
                @else
                    <a class="button primary" data-rental-send-button href="{{ route('login') }}">Войти и отправить</a>
                @endauth
            </div>
        </section>
    @endif
</section>

<dialog class="app-venue-overlay app-venue-day-dialog" data-venue-day-dialog
    data-day-url="{{ route('venues.schedule.day', $venue->id) }}"
    data-court-id="{{ $court->id }}"
    aria-labelledby="app-venue-day-dialog-title">
    <div class="app-venue-overlay__header">
        <h2 id="app-venue-day-dialog-title" data-venue-day-title>Выбор времени</h2>
        <button type="button" data-venue-overlay-close aria-label="Закрыть">
            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M5 5L19 19M19 5L5 19"/></svg>
        </button>
    </div>
    @include('theme::pages.venues.partials.day-calendar')
    <div class="app-venue-day-dialog__info" data-venue-day-info>
        @foreach($venueSlotPromotions as $promotion)
            <div class="app-venue-day-dialog__promotion" aria-label="{{ $promotion['title'] }}">
                <strong>{{ $promotion['title'] }}</strong>
                @if($promotion['description'])<p class="muted">{{ $promotion['description'] }}</p>@endif
                @if($promotion['amount_caption'])
                    <p class="app-venue-day-dialog__promotion-amount">{{ $promotion['amount_caption'] }}</p>
                @endif
            </div>
        @endforeach
        <p class="app-venue-day-dialog__status" role="status" aria-live="polite" data-venue-day-status hidden></p>
    </div>
    <div class="app-venue-day-dialog__grid" data-venue-day-slots aria-label="Интервалы бронирования"></div>
    <form method="POST" action="{{ route('venues.rental.quote', $venue->id) }}"
        class="app-venue-day-dialog__footer" data-venue-day-booking-form>
        @csrf
        <input type="hidden" name="venue_court_id" data-venue-day-court>
        <input type="hidden" name="starts_at" data-venue-day-start>
        <input type="hidden" name="duration_minutes" data-venue-day-duration>
        <input type="hidden" name="scope" value="whole">
        <div class="app-venue-day-dialog__selection">
            <strong data-venue-day-summary>Выберите время</strong>
            <span data-venue-day-minimum></span>
        </div>
        <button type="submit" class="button primary" data-venue-day-book disabled>Выбрать это время</button>
    </form>
</dialog>
@if($quote)
    <dialog class="app-venue-overlay app-rental-pricing__conflict-dialog" data-rental-conflict-dialog
        aria-labelledby="rental-conflict-heading">
        <div class="app-venue-overlay__header">
            <h2 id="rental-conflict-heading">Время стало недоступно</h2>
            <button type="button" data-rental-conflict-close aria-label="Закрыть">
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M5 5L19 19M19 5L5 19"/></svg>
            </button>
        </div>
        <div class="app-rental-pricing__conflict-body">
            <p data-rental-conflict-message>К сожалению, выбранный интервал уже заняли.</p>
            <button class="button primary" type="button" data-rental-conflict-choose>Выбрать новое время</button>
        </div>
    </dialog>
@endif
@endsection
