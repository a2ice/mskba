@php
    $title = $ownerInbox ? 'Входящие заявки на аренду' : 'Мои бронирования';
    $bookingRows = collect($projection['data'] ?? []);
    $metadata = $projection['meta'] ?? ['current_page' => 1, 'last_page' => 1, 'total' => $bookingRows->count()];
@endphp
@extends('theme::layouts.app', ['title' => $title])

@section('content')
<section class="container app-rental-inbox" aria-labelledby="rental-inbox-heading">
    <header class="app-account-overview__intro">
        <h1 id="rental-inbox-heading">{{ $title }}</h1>
        <p>{{ $ownerInbox ? 'Заявки площадок, доступные вам для рассмотрения.' : 'Ваши заявки на аренду и их актуальные статусы.' }}</p>
    </header>

    <nav class="app-rental-inbox__tabs" aria-label="Заявки на аренду">
        <a class="button {{ $ownerInbox ? 'primary' : 'secondary' }}"
           @if($ownerInbox) aria-current="page" @endif
           href="{{ route('account.venue-bookings.inbox') }}">Входящие</a>
        <a class="button {{ !$ownerInbox ? 'primary' : 'secondary' }}"
           @if(!$ownerInbox) aria-current="page" @endif
           href="{{ route('account.venue-bookings.index') }}">Мои бронирования</a>
    </nav>

    <div class="app-rental-inbox__list" aria-live="polite">
        @forelse($bookingRows as $booking)
            @php
                $startsAt = \Carbon\CarbonImmutable::parse($booking['starts_at'])->setTimezone('Europe/Moscow');
                $endsAt = \Carbon\CarbonImmutable::parse($booking['ends_at'])->setTimezone('Europe/Moscow');
                $durationMinutes = (int) $startsAt->diffInMinutes($endsAt);
            @endphp
            <article class="panel app-rental-inbox__entry">
                <div class="app-rental-inbox__entry-head">
                    <h2>{{ $booking['venue']['name'] ?? 'Площадка' }}</h2>
                    <span class="app-rental-inbox__status" data-status="{{ $booking['status'] ?? '' }}">{{ $booking['status_label'] ?? 'Заявка' }}</span>
                </div>
                <p class="app-rental-inbox__date">{{ $startsAt->format('d.m.Y H:i') }}–{{ $endsAt->format('H:i') }} · {{ $durationMinutes }} мин</p>
                @if($ownerInbox && isset($booking['requester']['name']))
                    <p class="app-rental-inbox__muted">Заявитель: {{ $booking['requester']['name'] }}</p>
                @endif
                <div class="app-rental-inbox__entry-bottom">
                    <span class="app-rental-inbox__muted">Версия {{ $booking['version'] ?? 1 }}</span>
                    <a class="button primary" href="{{ route('account.venue-bookings.show', $booking['booking_id']) }}">Открыть заявку</a>
                </div>
            </article>
        @empty
            <div class="panel app-rental-inbox__empty">
                <strong>Заявок пока нет</strong>
                <p class="app-rental-inbox__muted">{{ $ownerInbox ? 'Новые обращения появятся здесь после отправки пользователем.' : 'Выберите площадку и подходящее время для аренды.' }}</p>
                @unless($ownerInbox)
                    <a class="button primary" href="{{ route('venues') }}">Посмотреть площадки</a>
                @endunless
            </div>
        @endforelse
    </div>

    @if(($metadata['last_page'] ?? 1) > 1)
        <nav class="app-rental-inbox__pages" aria-label="Страницы заявок">
            @for($page = 1; $page <= $metadata['last_page']; $page++)
                <a class="button {{ $page === $metadata['current_page'] ? 'primary' : 'secondary' }}"
                   @if($page === $metadata['current_page']) aria-current="page" @endif
                   href="{{ request()->fullUrlWithQuery(['page' => $page]) }}">{{ $page }}</a>
            @endfor
        </nav>
    @endif
</section>
@endsection
