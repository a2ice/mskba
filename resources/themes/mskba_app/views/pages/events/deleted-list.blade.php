@extends('theme::layouts.account', ['title' => 'Удалённые мероприятия'])
@section('account-heading')
    @include('theme::pages.account.partials.section-heading', ['heading' => 'Удалённые мероприятия'])
@endsection
@section('account-content')
<section class="account-collection">
    @forelse($events as $event)
        <article class="panel" style="padding:20px">
            <h3><a href="{{ route('events.lifecycle.show', $event->routeIdentifier()) }}">{{ $event->title }}</a></h3>
            <span class="badge">Удалено</span>
            <a class="button secondary" href="{{ route('events.lifecycle.show', $event->routeIdentifier()) }}">Открыть</a>
        </article>
    @empty
        <p class="muted">Удалённых мероприятий нет.</p>
    @endforelse
    {{ $events->links() }}
</section>
@endsection
