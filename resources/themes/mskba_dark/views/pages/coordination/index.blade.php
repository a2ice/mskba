@php $title = 'Опросы'; @endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'coordination',
    'sectionClass' => 'coordination-section',
    'contentTitle' => 'Опросы и согласования',
    'contentSubtitle' => 'Создавайте опросы, собирайте ответы и фиксируйте итоговое решение.',
    'sidebarLabel' => 'Навигация опросов',
])

@section('section-heading-action')
    @can('coordination-create')
        <a class="btn btn--primary btn--sm" href="{{ route('coordination.create') }}">Создать опрос</a>
    @else
        @guest
            <button
                type="button"
                class="btn btn--primary btn--sm js-handler"
                data-handler="modal"
                data-modal-action="open"
                data-modal-target="auth-entry-classic"
                data-auth-redirect-url="{{ route('coordination.create', [], false) }}"
            >
                Создать опрос
            </button>
        @endguest
    @endcan
@endsection

@section('section-sidebar')
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Опросы</h2>
        <ul class="sidebar-nav nav flex-column">
            <li class="nav-item active"><a class="nav-link active" href="{{ route('coordination.index') }}">Все опросы</a></li>
            @can('coordination-create')
                <li class="nav-item"><a class="nav-link" href="{{ route('coordination.create') }}">Создать</a></li>
            @endcan
        </ul>
    </div>
@endsection

@section('section-content')
    @if($sessions->isEmpty())
        <div class="alert alert-info">Опросов пока нет.</div>
    @else
        <div class="section-list">
            @foreach($sessions as $session)
                @php $poll = $session->polls->first(); @endphp
                <article class="section-list-item coordination-card">
                    <div class="coordination-card__meta">
                        <span class="badge badge--{{ $session->status->value === 'open' ? 'success' : ($session->status->value === 'cancelled' ? 'danger' : 'warning') }}">{{ $session->status->label() }}</span>
                        @if($poll && $poll->status->value === 'open' && $poll->closes_at->isFuture())
                            <time datetime="{{ $poll->closes_at->toIso8601String() }}">до {{ $poll->closes_at->format('d.m.Y H:i') }}</time>
                        @endif
                    </div>
                    <h2 class="h4 mb-2"><a href="{{ route('coordination.show', $session) }}">{{ $session->title }}</a></h2>
                    @if($poll)
                        @if(trim($poll->question) !== '')
                            <p class="mb-2">{{ $poll->question }}</p>
                        @endif
                        <p class="mb-3">Проголосовали: {{ $poll->ballots_count }}</p>
                    @endif
                    <a class="btn btn--secondary btn--sm" href="{{ route('coordination.show', $session) }}">Подробнее</a>
                </article>
            @endforeach
        </div>
        <div class="mt-4">{{ $sessions->links() }}</div>
    @endif
@endsection
