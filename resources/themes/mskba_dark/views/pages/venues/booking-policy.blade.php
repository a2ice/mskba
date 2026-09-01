@php
    $title = 'Условия аренды · '.$venue->name;
    $fieldValue = static fn(string $key, mixed $default = null): mixed => old($key, $policy?->{$key} ?? $default);
    $currency = strtoupper((string) $fieldValue('currency', 'RUB'));
    $currencyLabel = $currency === 'RUB' ? '₽' : $currency;
    $amounts = app(\App\Modules\VenueBooking\Application\Services\MinorAmountParser::class);
    $canRentHalves = (int) ($venue->characteristics?->hoops_count ?? 0) >= 2;
    $priceValue = static function (string $input, string $policyField, ?int $default = null) use ($policy, $amounts, $currency): string {
        if (session()->hasOldInput($input)) {
            return (string) old($input, '');
        }

        $minor = $policy?->{$policyField} ?? $default;

        return $minor === null ? '' : $amounts->format((int) $minor, $currency);
    };
    $venueSidebarActive = 'booking-policy';
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => $title,
    'sidebarLabel' => 'Управление площадкой',
])

@section('section-sidebar')
    @include('theme::partials.venues.internal-sidebar')
@endsection

@section('section-content')
        @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
        @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
        @if($policy)<p class="text-muted mb-3">Активная версия: №{{ $policy->version }}, опубликована {{ $policy->published_at->format('d.m.Y H:i') }}. Сохранение создаст новую версию.</p>@endif

        <form method="POST" action="{{ route('account.venues.booking-policy.update', $venue) }}" class="card"><div class="card-body">
            @csrf @method('PUT')
            @include('theme::partials.forms.toggle', ['name' => 'is_enabled', 'title' => 'Принимать заявки на аренду', 'description' => 'Отдельно включает приём новых заявок. Статусы площадки «активна» и «подтверждена» при этом не изменяются.', 'checked' => $fieldValue('is_enabled', false)])
            @include('theme::partials.forms.toggle', ['name' => 'allows_whole', 'title' => 'Разрешить аренду всей площадки', 'checked' => $fieldValue('allows_whole', true)])
            @include('theme::partials.forms.toggle', [
                'name' => 'allows_halves',
                'title' => 'Разрешить аренду половин',
                'description' => $canRentHalves
                    ? 'Можно сдавать половину А и половину Б независимо друг от друга.'
                    : 'Недоступно: в характеристиках площадки должно быть указано минимум две игровые зоны.',
                'checked' => $canRentHalves && $fieldValue('allows_halves', false),
                'inputAttributes' => ['disabled' => ! $canRentHalves],
            ])

            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">Минимальная длительность, мин</label><input class="form-control" type="number" name="minimum_duration_minutes" min="15" max="1440" required value="{{ $fieldValue('minimum_duration_minutes', 60) }}"></div>
                <div class="col-md-4"><label class="form-label">Максимальная длительность, мин</label><input class="form-control" type="number" name="maximum_duration_minutes" min="15" max="1440" required value="{{ $fieldValue('maximum_duration_minutes', 180) }}"></div>
                <div class="col-md-4"><label class="form-label">Шаг времени, мин</label><input class="form-control" type="number" name="time_step_minutes" min="5" max="240" required value="{{ $fieldValue('time_step_minutes', 30) }}"></div>
                <div class="col-md-4"><label class="form-label">Минимум до начала, мин</label><input class="form-control" type="number" name="minimum_lead_time_minutes" min="0" required value="{{ $fieldValue('minimum_lead_time_minutes', 120) }}"></div>
                <div class="col-md-4"><label class="form-label">Горизонт бронирования, дней</label><input class="form-control" type="number" name="maximum_advance_days" min="1" max="730" required value="{{ $fieldValue('maximum_advance_days', 90) }}"></div>
                <div class="col-md-4"><label class="form-label">Валюта ISO</label><input class="form-control" name="currency" minlength="3" maxlength="3" required value="{{ $fieldValue('currency', 'RUB') }}"></div>
                <div class="col-md-6">
                    <label class="form-label">Цена всей площадки за выбранный шаг, {{ $currencyLabel }}</label>
                    <input class="form-control @error('whole_price_per_step') is-invalid @enderror" type="text" inputmode="decimal" name="whole_price_per_step" required value="{{ $priceValue('whole_price_per_step', 'whole_price_per_step_minor', 0) }}">
                    @error('whole_price_per_step')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <small class="text-muted">Например, 1500 — это 1 500 {{ $currencyLabel }} за выбранный шаг времени.</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Цена половины за выбранный шаг, {{ $currencyLabel }}</label>
                    <input class="form-control @error('half_price_per_step') is-invalid @enderror" type="text" inputmode="decimal" name="half_price_per_step" value="{{ $priceValue('half_price_per_step', 'half_price_per_step_minor') }}">
                    @error('half_price_per_step')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <small class="text-muted">Можно оставить пустой для бесплатной аренды или если аренда половин отключена.</small>
                </div>
                <div class="col-md-4"><label class="form-label">Hold, мин</label><input class="form-control" type="number" name="hold_duration_minutes" min="1" max="1440" required value="{{ $fieldValue('hold_duration_minutes', 30) }}"></div>
                <div class="col-md-4"><label class="form-label">Максимальное продление hold, мин</label><input class="form-control" type="number" name="maximum_hold_extension_minutes" min="1" max="1440" value="{{ $fieldValue('maximum_hold_extension_minutes', 30) }}"></div>
                <div class="col-md-4"><label class="form-label">Платёжное окно, мин</label><input class="form-control" type="number" name="payment_window_minutes" min="1" max="1440" value="{{ $fieldValue('payment_window_minutes', 30) }}"></div>
                <div class="col-md-4"><label class="form-label">Срок quote, мин</label><input class="form-control" type="number" name="quote_validity_minutes" min="1" max="120" required value="{{ $fieldValue('quote_validity_minutes', 15) }}"></div>
                <div class="col-md-6"><label class="form-label">Бесплатная отмена не позднее, мин</label><input class="form-control" type="number" name="cancellation_before_minutes" min="0" value="{{ $fieldValue('cancellation_before_minutes') }}"></div>
            </div>
            @include('theme::partials.forms.toggle', ['name' => 'requires_payment', 'title' => 'Требуется оплата', 'checked' => $fieldValue('requires_payment', true), 'wrapperClass' => 'form-group field my-3'])
            @include('theme::partials.forms.toggle', ['name' => 'allows_hold_extension', 'title' => 'Разрешить согласование продления hold', 'checked' => $fieldValue('allows_hold_extension', false), 'wrapperClass' => 'form-group field my-3'])
            <button class="btn btn--primary btn--sm" type="submit">Опубликовать новую версию</button>
        </div></form>
@endsection
