@extends('theme::layouts.app', ['title' => 'Запросы на аренду'])
@section('content')
<section class="container app-rental-inbox">
    <header class="app-account-overview__intro"><h1>Запросы на аренду</h1></header>
    <nav class="app-rental-inbox__tabs" aria-label="Заявки на аренду">
        <a class="button secondary" href="{{ route('account.venue-bookings.index') }}">Мои бронирования</a>
        <a class="button secondary" href="{{ route('account.venue-bookings.inbox') }}">Входящие брони</a>
    </nav>
    <div class="app-rental-inbox__list">
        @forelse($preRequests as $pre)
            <article class="panel app-rental-inbox__entry">
                <h2>{{ $pre->venue->name }} · {{ $pre->court->publicName() }}</h2>
                <p>{{ $pre->status->label() }} · {{ $pre->starts_at->setTimezone($pre->venue->schedule()->value('timezone') ?: config('app.timezone'))->format('d.m.Y H:i') }} · {{ $pre->requester->username }}</p>
                <a class="button primary" href="{{ route('account.venue-pre-requests.show', $pre) }}">Переписка и предложения</a>
            </article>
        @empty
            <p>Запросов на аренду пока нет.</p>
        @endforelse
    </div>
    {{ $preRequests->links() }}
</section>
@endsection
