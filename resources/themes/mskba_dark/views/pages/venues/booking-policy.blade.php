@php
    $title = 'Условия аренды · '.$venue->name;
    $value = static fn(string $key, mixed $default = null): mixed => old($key, $policy?->{$key} ?? $default);
@endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section class="first-screen"><div class="inner">
        @include('theme::partials.breadcrumbs')
        <h1 class="section-title mb-4">{{ $title }}</h1>
        @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
        @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
        @if($policy)<p class="text-muted mb-3">Активная версия: №{{ $policy->version }}, опубликована {{ $policy->published_at->format('d.m.Y H:i') }}. Сохранение создаст новую версию.</p>@endif

        <form method="POST" action="{{ route('account.venues.booking-policy.update', $venue) }}" class="card"><div class="card-body">
            @csrf @method('PUT')
            @include('theme::partials.forms.toggle', ['name' => 'is_enabled', 'title' => 'Принимать заявки на аренду', 'checked' => $value('is_enabled', false)])
            @include('theme::partials.forms.toggle', ['name' => 'allows_whole', 'title' => 'Разрешить аренду всей площадки', 'checked' => $value('allows_whole', true)])
            @include('theme::partials.forms.toggle', ['name' => 'allows_halves', 'title' => 'Разрешить аренду половин', 'description' => 'Доступно только при наличии минимум двух игровых зон.', 'checked' => $value('allows_halves', false)])

            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">Минимальная длительность, мин</label><input class="form-control" type="number" name="minimum_duration_minutes" min="15" max="1440" required value="{{ $value('minimum_duration_minutes', 60) }}"></div>
                <div class="col-md-4"><label class="form-label">Максимальная длительность, мин</label><input class="form-control" type="number" name="maximum_duration_minutes" min="15" max="1440" required value="{{ $value('maximum_duration_minutes', 180) }}"></div>
                <div class="col-md-4"><label class="form-label">Шаг времени, мин</label><input class="form-control" type="number" name="time_step_minutes" min="5" max="240" required value="{{ $value('time_step_minutes', 30) }}"></div>
                <div class="col-md-4"><label class="form-label">Минимум до начала, мин</label><input class="form-control" type="number" name="minimum_lead_time_minutes" min="0" required value="{{ $value('minimum_lead_time_minutes', 120) }}"></div>
                <div class="col-md-4"><label class="form-label">Горизонт бронирования, дней</label><input class="form-control" type="number" name="maximum_advance_days" min="1" max="730" required value="{{ $value('maximum_advance_days', 90) }}"></div>
                <div class="col-md-4"><label class="form-label">Валюта ISO</label><input class="form-control" name="currency" minlength="3" maxlength="3" required value="{{ $value('currency', 'RUB') }}"></div>
                <div class="col-md-6"><label class="form-label">Цена всей площадки за шаг, minor units</label><input class="form-control" type="number" name="whole_price_per_step_minor" min="0" required value="{{ $value('whole_price_per_step_minor', 0) }}"><small class="text-muted">Для RUB: 100 = 1 ₽.</small></div>
                <div class="col-md-6"><label class="form-label">Цена половины за шаг, minor units</label><input class="form-control" type="number" name="half_price_per_step_minor" min="0" value="{{ $value('half_price_per_step_minor') }}"></div>
                <div class="col-md-4"><label class="form-label">Hold, мин</label><input class="form-control" type="number" name="hold_duration_minutes" min="1" max="1440" required value="{{ $value('hold_duration_minutes', 30) }}"></div>
                <div class="col-md-4"><label class="form-label">Максимальное продление hold, мин</label><input class="form-control" type="number" name="maximum_hold_extension_minutes" min="1" max="1440" value="{{ $value('maximum_hold_extension_minutes', 30) }}"></div>
                <div class="col-md-4"><label class="form-label">Платёжное окно, мин</label><input class="form-control" type="number" name="payment_window_minutes" min="1" max="1440" value="{{ $value('payment_window_minutes', 30) }}"></div>
                <div class="col-md-4"><label class="form-label">Срок quote, мин</label><input class="form-control" type="number" name="quote_validity_minutes" min="1" max="120" required value="{{ $value('quote_validity_minutes', 15) }}"></div>
                <div class="col-md-6"><label class="form-label">Бесплатная отмена не позднее, мин</label><input class="form-control" type="number" name="cancellation_before_minutes" min="0" value="{{ $value('cancellation_before_minutes') }}"></div>
            </div>
            @include('theme::partials.forms.toggle', ['name' => 'requires_payment', 'title' => 'Требуется оплата', 'checked' => $value('requires_payment', true), 'wrapperClass' => 'form-group field my-3'])
            @include('theme::partials.forms.toggle', ['name' => 'allows_hold_extension', 'title' => 'Разрешить согласование продления hold', 'checked' => $value('allows_hold_extension', false), 'wrapperClass' => 'form-group field my-3'])
            <button class="btn btn--primary btn--sm" type="submit">Опубликовать новую версию</button>
        </div></form>
    </div></section>
@endsection
