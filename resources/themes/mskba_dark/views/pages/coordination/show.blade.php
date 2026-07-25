@php
    $title = $coordination->title;
    $isOpen = $poll->status->value === 'open' && $poll->closes_at->isFuture();
    $canSubmitVote = $isOpen && ($ballot === null || $poll->allows_vote_changes);
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'coordination',
    'sectionClass' => 'coordination-section',
    'contentTitle' => $title,
    'contentSubtitle' => $coordination->description,
    'sidebarLabel' => 'Навигация опросов',
])

@section('section-sidebar')
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Опросы</h2>
        <ul class="sidebar-nav nav flex-column">
            <li class="nav-item"><a class="nav-link" href="{{ route('coordination.index') }}">Все опросы</a></li>
            @can('coordination-create')
                <li class="nav-item"><a class="nav-link" href="{{ route('coordination.create') }}">Создать</a></li>
            @endcan
        </ul>
    </div>
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Состояние</h2>
        <p class="mb-2">{{ $coordination->status->label() }}</p>
        <p class="mb-0">до {{ $poll->closes_at->format('d.m.Y H:i') }}</p>
    </div>
@endsection

@section('section-content')
    @if(session('status')) <div class="alert alert-success mb-3">{{ session('status') }}</div> @endif
    @if(session('error')) <div class="alert alert-danger mb-3">{{ session('error') }}</div> @endif

    <article class="coordination-poll">
        <div class="coordination-poll__heading">
            <div>
                <span class="badge badge--{{ $isOpen ? 'success' : ($poll->status->value === 'cancelled' ? 'danger' : 'warning') }}">{{ $poll->status->label() }}</span>
                <h2 class="h3 mt-2 mb-1">{{ $poll->question }}</h2>
                <p class="mb-0">{{ $poll->selection_mode->label() }}</p>
            </div>
            <time datetime="{{ $poll->closes_at->toIso8601String() }}">до {{ $poll->closes_at->format('d.m.Y H:i') }}</time>
        </div>

        @auth
            @if($canSubmitVote)
                <form method="POST" action="{{ route('coordination.vote', $coordination) }}" class="coordination-vote">
                    @csrf
                    @foreach($poll->options as $option)
                        <label class="coordination-vote-option">
                            <input
                                type="{{ $poll->selection_mode->value === 'single' ? 'radio' : 'checkbox' }}"
                                name="option_ids[]"
                                value="{{ $option->id }}"
                                @checked(in_array($option->id, $selectedOptionIds, true))
                            >
                            <span class="coordination-vote-option__copy">
                                <span>{{ $option->label }}</span>
                                @include('theme::pages.coordination.partials.option-voters', compact('poll', 'option', 'canSeeResults'))
                            </span>
                            @if($canSeeResults)<strong>{{ $option->selections_count }}</strong>@endif
                        </label>
                    @endforeach
                    @error('option_ids') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    <button class="btn btn--primary mt-3" type="submit">{{ $ballot ? 'Изменить голос' : 'Проголосовать' }}</button>
                </form>
            @else
                @if($isOpen && $ballot && !$poll->allows_vote_changes)
                    <div class="alert alert-info mt-3">Ваш голос принят и не может быть изменён.</div>
                @endif
                <div class="coordination-results">
                    @foreach($poll->options as $option)
                        <div class="coordination-result">
                            <span class="coordination-vote-option__copy">
                                <span>{{ $option->label }}</span>
                                @include('theme::pages.coordination.partials.option-voters', compact('poll', 'option', 'canSeeResults'))
                            </span>
                            <strong>{{ $canSeeResults ? $option->selections_count : '—' }}</strong>
                        </div>
                    @endforeach
                </div>
            @endif
        @else
            <div class="alert alert-info mt-3">Войдите, чтобы проголосовать.</div>
        @endauth
    </article>

    @if($coordination->decision)
        <div class="section-list-item mt-4">
            <span class="badge badge--success">Решение принято</span>
            <h2 class="h4 mt-2 mb-1">{{ $coordination->decision->option->label }}</h2>
            <p class="mb-0">Итоговый вариант зафиксирован.</p>
        </div>
    @endif

    @if($canManage && !in_array($coordination->status->value, ['completed', 'cancelled'], true))
        <div class="coordination-management mt-4">
            @if($isOpen)
                <form method="POST" action="{{ route('coordination.close', $coordination) }}">
                    @csrf
                    <button class="btn btn--secondary" type="submit" onclick="return confirm('Закрыть голосование?')">Закрыть голосование</button>
                </form>
            @elseif($coordination->status->value === 'decision_pending')
                <form method="POST" action="{{ route('coordination.decision', $coordination) }}" class="coordination-decision">
                    @csrf
                    <label class="form-label" for="coordinationDecision">Принять итоговый вариант</label>
                    <select id="coordinationDecision" class="form-select" name="option_id" required>
                        @foreach($poll->options as $option)
                            <option value="{{ $option->id }}">{{ $option->label }} — {{ $option->selections_count }}</option>
                        @endforeach
                    </select>
                    <button class="btn btn--primary" type="submit" onclick="return confirm('Зафиксировать выбранный результат?')">Принять результат</button>
                </form>
            @endif
            <form method="POST" action="{{ route('coordination.cancel', $coordination) }}">
                @csrf
                <button class="btn btn--danger" type="submit" onclick="return confirm('Отменить согласование?')">Отменить</button>
            </form>
        </div>
    @endif
@endsection
