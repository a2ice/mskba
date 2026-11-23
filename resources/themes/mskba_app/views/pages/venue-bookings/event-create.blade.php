@php
    $timezone = $booking->venue->schedule?->timezone ?: 'Europe/Moscow';
    $startsAt = $booking->starts_at->setTimezone($timezone);
    $endsAt = $booking->ends_at->setTimezone($timezone);
@endphp
@extends('theme::layouts.app', ['title' => 'Мероприятие из бронирования'])

@section('content')
<section class="app-account-section">
    <div class="container app-account-section__inner">
        <header class="app-account-section__heading">
            <p><a href="{{ route('account.venue-bookings.show', $booking) }}">← К бронированию</a></p>
            <h1>Создать мероприятие</h1>
            <p>Мероприятие будет привязано к вашей подтверждённой брони. Площадка, зал и время берутся из неё автоматически и не изменяются при создании.</p>
        </header>

        <section class="panel" aria-label="Данные бронирования">
            <h2>Забронированный интервал</h2>
            <dl class="app-rental-inbox__details">
                <div><dt>Площадка</dt><dd>{{ $booking->venue->name }}</dd></div>
                <div><dt>Зал</dt><dd>{{ $booking->court?->publicName() ?? 'Вся площадка' }}</dd></div>
                <div><dt>Дата и время</dt><dd>{{ $startsAt->format('d.m.Y H:i') }}–{{ $endsAt->format('d.m.Y H:i') }}</dd></div>
                <div><dt>Бронирование</dt><dd>{{ $booking->public_id }}</dd></div>
            </dl>
        </section>

        @if(session('error'))
            <div class="panel" role="alert">{{ session('error') }}</div>
        @endif
        @if($errors->any())
            <div class="panel" role="alert">
                <h2>Проверьте данные</h2>
                @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('account.venue-bookings.event.store', $booking) }}" class="panel">
            @csrf
            <div class="field-group">
                <label for="bookingEventTitle">Название мероприятия</label>
                <input id="bookingEventTitle" name="title" type="text" maxlength="255"
                    value="{{ old('title') }}" placeholder="Например, вечерняя игра" required>
            </div>
            <div class="field-group">
                <label for="bookingEventType">Тип мероприятия</label>
                <span class="select-control">
                    <select id="bookingEventType" name="type" required>
                        @foreach($types as $type)
                            <option value="{{ $type->value }}" @selected(old('type') === $type->value)>{{ $type->label() }}</option>
                        @endforeach
                    </select>
                </span>
            </div>
            <div class="field-group">
                <label for="bookingEventVisibility">Публичность</label>
                <span class="select-control">
                    <select id="bookingEventVisibility" name="visibility" required>
                        @foreach($visibilities as $visibility)
                            <option value="{{ $visibility->value }}"
                                @selected(old('visibility', 'public') === $visibility->value)>{{ $visibility->label() }}</option>
                        @endforeach
                    </select>
                </span>
            </div>
            <div class="field-group">
                <label for="bookingEventCapacity">Максимум участников (необязательно)</label>
                <input id="bookingEventCapacity" type="number" name="max_participants" min="2" max="1000"
                    value="{{ old('max_participants') }}">
            </div>
            @include('components.event-cost-inclusion-fields', ['pricingEvent' => null])
            <div class="field-group">
                <label for="bookingEventDescription">Описание (необязательно)</label>
                <textarea id="bookingEventDescription" name="description" maxlength="10000" rows="4">{{ old('description') }}</textarea>
            </div>
            <div class="app-account-section__actions">
                <button type="submit" class="button primary">Создать мероприятие</button>
                <a href="{{ route('account.venue-bookings.show', $booking) }}" class="button secondary">Отмена</a>
            </div>
        </form>
    </div>
</section>
@endsection
