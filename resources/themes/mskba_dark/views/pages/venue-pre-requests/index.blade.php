@extends('theme::layouts.app', ['title' => 'Предзаявки на аренду'])
@section('content')
<section class="container">
    <header class="mb-4"><h1>Предзаявки на аренду</h1></header>
    <nav class="venue-management-actions mb-3" aria-label="Заявки на аренду">
        <a class="btn btn--secondary btn--sm" href="{{ route('account.venue-bookings.index') }}">Мои бронирования</a>
        <a class="btn btn--secondary btn--sm" href="{{ route('account.venue-bookings.inbox') }}">Входящие брони</a>
    </nav>
    <div class="mb-3">
        @forelse($preRequests as $pre)
            <article class="card card-body mb-3">
                <h2>{{ $pre->venue->name }} · {{ $pre->court->publicName() }}</h2>
                <p>{{ $pre->status->label() }} · {{ $pre->starts_at->setTimezone($pre->venue->schedule()->value('timezone') ?: config('app.timezone'))->format('d.m.Y H:i') }} · {{ $pre->requester->username }}</p>
                <a class="btn btn--primary btn--sm" href="{{ route('account.venue-pre-requests.show', $pre) }}">Переписка и предложения</a>
            </article>
        @empty
            <p>Предзаявок пока нет.</p>
        @endforelse
    </div>
    {{ $preRequests->links() }}
</section>
@endsection
