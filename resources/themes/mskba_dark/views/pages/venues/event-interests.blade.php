@php
    $title = 'Предварительный интерес · '.$venue->name;
    $venueSidebarActive = 'event-interests';
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
    <div class="alert alert-info mb-3">
        Предзаявки — только сигнал спроса для планирования. Они не блокируют время, не фиксируют стоимость,
        не обязывают площадку предоставлять зал и не подтверждают бронь.
    </div>
    <div class="d-flex flex-wrap gap-3 mb-4">
        <span>Открытые: <strong>{{ $totals['open'] ?? 0 }}</strong></span>
        <span>Изменённые: <strong>{{ $totals['revised'] ?? 0 }}</strong></span>
        <span>Перешли к заявке: <strong>{{ $totals['booking_requested'] ?? 0 }}</strong></span>
        <span>Отозваны: <strong>{{ $totals['withdrawn'] ?? 0 }}</strong></span>
    </div>
    @forelse($interests as $interest)
        @php
            $timezone = $venue->schedule()->value('timezone') ?: 'Europe/Moscow';
            $labels = ['open' => 'Интерес', 'revised' => 'Заменена новой версией',
                'booking_requested' => 'Подана заявка на бронь', 'withdrawn' => 'Отозвана'];
        @endphp
        <article class="card mb-3"><div class="card-body">
            <div class="d-flex justify-content-between gap-2 flex-wrap">
                <strong>{{ $interest->event?->title ?? 'Мероприятие удалено' }}</strong>
                <span>{{ $labels[$interest->status] ?? $interest->status }}</span>
            </div>
            <p class="mt-2 mb-2">
                {{ $interest->court?->name ?? 'Зал не указан' }} ·
                {{ $interest->starts_at->setTimezone($timezone)->format('d.m.Y H:i') }} –
                {{ $interest->ends_at->setTimezone($timezone)->format('H:i') }}
                · версия {{ $interest->revision }}
            </p>
            @if($interest->event)
                <a href="{{ route('events.interests.show', ['event' => $interest->event->routeIdentifier(), 'interest' => $interest->id]) }}" class="fc-link me-3">Открыть переписку и действия</a>
            @endif
            @if($interest->event && $interest->event->visibility->value === 'public')
                <a href="{{ route('events.show', $interest->event->routeIdentifier()) }}" class="fc-link">Мероприятие</a>
            @endif
        </div></article>
    @empty
        <p>Интерес к аренде пока не поступал.</p>
    @endforelse
    {{ $interests->links() }}
@endsection
