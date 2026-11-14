@php
    $title = 'Предварительный интерес · '.$venue->name;
    $editor = app(\App\Modules\Venue\Application\Services\AccountVenueEditorAccess::class)
        ->capabilities(auth()->user(), $venue);
@endphp
@extends('theme::layouts.account', ['title' => $title])
@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => $venue->name, 'subtitle' => 'Предварительный интерес к аренде',
    ])
@endsection
@section('account-content')
<div class="app-venue-editor" data-venue-editor>
    @include('theme::pages.venues.partials.editor-tabs', ['activeTab' => 'rental', 'editor' => $editor])
    <section class="panel" aria-labelledby="interest-heading">
        <h2 id="interest-heading">Предварительные заявки</h2>
        <p>Это только информация для планирования. Предзаявки не резервируют время и не создают обязательств.</p>
        <p>Открытые: <strong>{{ $totals['open'] ?? 0 }}</strong> ·
            Изменённые: <strong>{{ $totals['revised'] ?? 0 }}</strong> ·
            Перешедшие к брони: <strong>{{ $totals['booking_requested'] ?? 0 }}</strong> ·
            Отозванные: <strong>{{ $totals['withdrawn'] ?? 0 }}</strong></p>
    </section>
    @forelse($interests as $interest)
        @php
            $timezone = $venue->schedule()->value('timezone') ?: 'Europe/Moscow';
            $state = match($interest->status) {
                'open' => 'Интерес', 'revised' => 'Изменена', 'booking_requested' => 'Создана заявка на аренду',
                'withdrawn' => 'Отозвана', default => $interest->status,
            };
        @endphp
        <article class="panel">
            <h3>{{ $interest->event?->title ?? 'Мероприятие' }}</h3>
            <p>{{ $interest->court?->name ?? 'Зал' }} ·
                {{ $interest->starts_at->setTimezone($timezone)->format('d.m.Y H:i') }}–{{ $interest->ends_at->setTimezone($timezone)->format('H:i') }}
                · Ревизия {{ $interest->revision }}</p>
            <p>Статус: <strong>{{ $state }}</strong></p>
            @if($interest->event)
                <a class="button secondary" href="{{ route('events.interests.show', [
                    'event' => $interest->event->routeIdentifier(), 'interest' => $interest->id,
                ]) }}">Переписка и действия</a>
            @endif
        </article>
    @empty
        <section class="panel"><p>Предварительных заявок пока нет.</p></section>
    @endforelse
    {{ $interests->links() }}
</div>
@endsection
