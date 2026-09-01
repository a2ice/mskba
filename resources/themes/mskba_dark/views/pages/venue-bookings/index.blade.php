@php $title = $ownerInbox ? 'Входящие заявки на аренду' : 'Мои бронирования'; @endphp
@extends('theme::layouts.app', ['title' => $title])

@section('content')
<section class="first-screen"><div class="inner">
    @include('theme::partials.breadcrumbs')
    <h1 class="section-title mb-4">{{ $title }}</h1>
    <div aria-live="polite">
        @forelse($projection['data'] as $booking)
            <article class="card mb-3"><div class="card-body">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <h2 class="h5">{{ $booking['venue']['name'] }}</h2>
                    <span class="badge">{{ $booking['status_label'] }}</span>
                </div>
                <p>{{ \Carbon\CarbonImmutable::parse($booking['starts_at'])->setTimezone(config('app.timezone'))->format('d.m.Y H:i') }}</p>
                <p class="text-muted">Версия {{ $booking['version'] }}</p>
                <a class="btn btn--primary btn--sm" href="{{ route('account.venue-bookings.show', $booking['booking_id']) }}">Открыть заявку</a>
            </div></article>
        @empty
            <div class="card"><div class="card-body"><p>Заявок пока нет.</p></div></div>
        @endforelse
    </div>
    @if($projection['meta']['last_page'] > 1)
        <nav aria-label="Страницы заявок" class="venue-management-actions">
            @for($page = 1; $page <= $projection['meta']['last_page']; $page++)
                <a class="btn btn--secondary btn--sm" @if($page === $projection['meta']['current_page']) aria-current="page" @endif href="{{ request()->fullUrlWithQuery(['page' => $page]) }}">{{ $page }}</a>
            @endfor
        </nav>
    @endif
</div></section>
@endsection
