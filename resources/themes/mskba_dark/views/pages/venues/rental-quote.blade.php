@php $title = 'Расчёт аренды · '.$venue->name; @endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section class="first-screen"><div class="inner">
        @include('theme::partials.breadcrumbs')
        <h1 class="section-title mb-4">{{ $title }}</h1>
        @if(isset($error))<div class="alert alert-danger">{{ $error['message'] }}</div>@endif
        <div class="card mb-4"><div class="card-body">
            <p>Расчёт выполняется по версии условий №{{ $policy->version }}. Он не резервирует зал.</p>
            <form method="POST" action="{{ route('venues.rental.quote', $venue) }}" data-venue-rental-court-form>
                @csrf
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="venue-rental-court">Зал</label>
                        @if(($courts ?? collect())->count() > 1)
                            <select id="venue-rental-court" class="form-select" name="venue_court_id" required data-venue-rental-court>
                                @foreach($courts as $courtOption)
                                    <option
                                        value="{{ $courtOption->id }}"
                                        data-allows-whole="{{ $courtOption->allows_whole ? '1' : '0' }}"
                                        data-allows-halves="{{ ($courtOption->supports_halves && $courtOption->allows_halves) ? '1' : '0' }}"
                                        @selected((int) old('venue_court_id', $court->id) === (int) $courtOption->id)
                                    >{{ $courtOption->name }}</option>
                                @endforeach
                            </select>
                        @else
                            <input type="hidden" name="venue_court_id" value="{{ $court->id }}">
                            <div class="form-control" aria-readonly="true">{{ $court->name }}</div>
                        @endif
                    </div>
                    <div class="col-md-4"><label class="form-label">Начало</label><input class="form-control" type="datetime-local" name="starts_at" required value="{{ old('starts_at') }}"></div>
                    <div class="col-md-2"><label class="form-label">Длительность, мин</label><input class="form-control" type="number" name="duration_minutes" required min="{{ $policy->minimum_duration_minutes }}" max="{{ $policy->maximum_duration_minutes }}" step="{{ $policy->time_step_minutes }}" value="{{ old('duration_minutes', $policy->minimum_duration_minutes) }}"></div>
                    <div class="col-md-2">
                        <label class="form-label" for="venue-rental-scope">Область</label>
                        <select id="venue-rental-scope" class="form-select" name="scope" data-venue-rental-scope>
                            <option value="whole" data-scope-whole @if(!$court->allows_whole) hidden disabled @endif>Весь зал</option>
                            <option value="half_a" data-scope-half @if(!($court->supports_halves && $court->allows_halves)) hidden disabled @endif>Половина A</option>
                            <option value="half_b" data-scope-half @if(!($court->supports_halves && $court->allows_halves)) hidden disabled @endif>Половина B</option>
                        </select>
                    </div>
                </div>
                <div class="text-muted small mt-2" data-venue-rental-scope-empty hidden>Для выбранного зала аренда сейчас отключена.</div>
                <button class="btn btn--primary btn--sm mt-3" type="submit" data-venue-rental-submit>Рассчитать</button>
            </form>
        </div></div>

        @if($quote)
            <div class="card"><div class="card-body">
                <h2 class="h4">Итог предложения</h2>
                <p><strong>{{ $court->name }}</strong></p>
                <p><strong>{{ number_format($quote->amountMinor / 100, 2, ',', ' ') }} {{ $quote->currency }}</strong></p>
                <p>{{ $quote->startsAt->setTimezone($venue->schedule?->timezone ?? config('app.timezone'))->format('d.m.Y H:i') }}–{{ $quote->endsAt->setTimezone($venue->schedule?->timezone ?? config('app.timezone'))->format('H:i') }}</p>
                <p>Hold: {{ $quote->holdDurationMinutes }} мин. Quote действует до {{ $quote->validUntil->format('d.m.Y H:i') }} UTC.</p>
                <p class="text-muted">Идентификатор: {{ $quote->publicId }}. При отправке заявки сервер повторно проверит этот snapshot.</p>
                @auth
                    <div class="venue-management-actions">
                        <form method="POST" action="{{ route('account.venue-bookings.store') }}">
                            @csrf
                            <input type="hidden" name="quote_id" value="{{ $quote->publicId }}">
                            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <button class="btn btn--primary btn--sm" type="submit">Отправить заявку</button>
                        </form>
                        @if(config('features.venue_rental.coordination'))
                            <form method="POST" action="{{ route('venue-rental-coordinations.store') }}">
                                @csrf
                                <input type="hidden" name="venue_id" value="{{ $venue->id }}">
                                <input type="hidden" name="venue_court_id" value="{{ $court->id }}">
                                <input type="hidden" name="starts_at" value="{{ $quote->startsAt->setTimezone($venue->schedule?->timezone ?? config('app.timezone'))->format('Y-m-d\TH:i') }}">
                                <input type="hidden" name="duration_minutes" value="{{ $quote->startsAt->diffInMinutes($quote->endsAt) }}">
                                <input type="hidden" name="scope" value="{{ $quote->scope->value }}">
                                <input type="hidden" name="participants_visibility" value="participants">
                                <label class="form-label" for="coordination-title">Название сбора</label>
                                <input class="form-control mb-2" id="coordination-title" name="title" maxlength="150" required value="{{ old('title', 'Ищем участников для аренды '.$venue->name.' · '.$court->name) }}">
                                <button class="btn btn--secondary btn--sm" type="submit">Собрать участников</button>
                            </form>
                        @endif
                    </div>
                @else
                    <p><a href="{{ route('login') }}">Войдите</a>, чтобы отправить заявку.</p>
                @endauth
            </div></div>
        @endif
    </div></section>

    <script>
    (() => {
        const form = document.querySelector('[data-venue-rental-court-form]');
        const courtSelect = form?.querySelector('[data-venue-rental-court]');
        const scopeSelect = form?.querySelector('[data-venue-rental-scope]');
        if (!form || !scopeSelect) return;

        const syncScopes = () => {
            const selected = courtSelect?.selectedOptions?.[0];
            const allowsWhole = selected ? selected.dataset.allowsWhole === '1' : {{ ($court->allows_whole ? 'true' : 'false') }};
            const allowsHalves = selected ? selected.dataset.allowsHalves === '1' : {{ (($court->supports_halves && $court->allows_halves) ? 'true' : 'false') }};
            const whole = allowsWhole;
            const halves = allowsHalves;

            scopeSelect.querySelectorAll('[data-scope-whole]').forEach((option) => {
                option.hidden = !whole;
                option.disabled = !whole;
            });
            scopeSelect.querySelectorAll('[data-scope-half]').forEach((option) => {
                option.hidden = !halves;
                option.disabled = !halves;
            });

            const firstEnabled = Array.from(scopeSelect.options).find((option) => !option.disabled);
            if (scopeSelect.selectedOptions[0]?.disabled && firstEnabled) scopeSelect.value = firstEnabled.value;
            const unavailable = !firstEnabled;
            const empty = form.querySelector('[data-venue-rental-scope-empty]');
            const submit = form.querySelector('[data-venue-rental-submit]');
            if (empty) empty.hidden = !unavailable;
            if (submit) submit.disabled = unavailable;
        };

        courtSelect?.addEventListener('change', syncScopes);
        syncScopes();
    })();
    </script>
@endsection
