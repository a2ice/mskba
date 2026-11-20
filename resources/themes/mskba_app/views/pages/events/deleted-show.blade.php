@extends('theme::layouts.app', ['title' => $event->title])
@section('content')
<section class="container" style="padding-block:24px">
    <article class="panel" style="display:grid;gap:16px;padding:24px">
        <h1>{{ $event->title }}</h1>
        <span class="badge">Удалено</span>
        <p>Причина: {{ $change->reason }}</p>
        @if($owner)
            <div class="app-event-actions">
                <button type="button" class="button primary" data-event-lifecycle-open>Восстановить мероприятие</button>
                <a class="button secondary" href="{{ route('account.events.deleted') }}">Удалённые мероприятия</a>
            </div>
        @endif
    </article>
</section>
@if($owner)
    @include('theme::pages.events.partials.lifecycle-dialog', ['restoring' => true])
@endif
@endsection
