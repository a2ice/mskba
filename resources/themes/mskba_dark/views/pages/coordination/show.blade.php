@php
    $title = $coordination->title;
    $isOpen = $poll->status->value === 'open' && $poll->closes_at->isFuture();
    $canSubmitVote = $isOpen && ($ballot === null || $poll->allows_vote_changes);
    $displayStatusLabel = $isOpen
        ? 'Открыт'
        : ($poll->status->value === 'cancelled' ? 'Отменён' : 'Закрыт');
    $displayStatusVariant = $isOpen
        ? 'success'
        : ($poll->status->value === 'cancelled' ? 'danger' : 'warning');
    $eventVotingTimeLabel = null;
    if ($eventVotingStartsAt) {
        $eventVotingTimeLabel = $eventVotingStartsAt->locale('ru')->translatedFormat('j F H:i');

        if ($eventVotingEndsAt) {
            $eventVotingTimeLabel .= $eventVotingStartsAt->isSameDay($eventVotingEndsAt)
                ? '–'.$eventVotingEndsAt->format('H:i')
                : ' — '.$eventVotingEndsAt->locale('ru')->translatedFormat('j F H:i');
        }
    }
    $eventVotingHasMap = $eventVotingVenue
        && $eventVotingLatitude !== null
        && $eventVotingLongitude !== null;
    $pollClosesAtLabel = $pollClosesAt->locale('ru')->translatedFormat('j F H:i');
    $breadcrumbs = [
        ['label' => 'Опросы', 'url' => route('coordination.index')],
        ['label' => $title],
    ];
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
        @if($isOpen)
            <p class="mb-0">до {{ $pollClosesAtLabel }}</p>
        @endif
    </div>
@endsection

@section('section-content')
    @if(session('status')) <div class="alert alert-success mb-3">{{ session('status') }}</div> @endif
    @if(session('error')) <div class="alert alert-danger mb-3">{{ session('error') }}</div> @endif

    <article class="coordination-poll">
        <div class="coordination-poll__heading">
            <div>
                <span class="badge badge--{{ $displayStatusVariant }}">{{ $displayStatusLabel }}</span>
                @if(trim($poll->question) !== '')
                    <h2 class="h3 mt-2 mb-1">{{ $poll->question }}</h2>
                @endif
            </div>
            @if($isOpen)
                <p class="mb-0">Проголосовать до: <time datetime="{{ $pollClosesAt->toIso8601String() }}">{{ $pollClosesAtLabel }}</time></p>
            @endif
        </div>

        @if($eventVotingVenue || $eventVotingTimeLabel || $eventVotingDate)
            <dl class="coordination-poll-context">
                @if($eventVotingVenue)
                    <div class="coordination-poll-context__item">
                        <dt>Площадка</dt>
                        <dd>
                            @if($eventVotingHasMap)
                                <button
                                    type="button"
                                    class="coordination-poll-context__location js-handler"
                                    data-handler="modal"
                                    data-modal-action="open"
                                    data-modal-target="coordination-venue-map"
                                    data-event-map-open
                                >
                                    <span>{{ $eventVotingVenue->name }}</span>
                                    @if($eventVotingAddress)
                                        <small>{{ $eventVotingAddress }}</small>
                                    @endif
                                </button>
                            @else
                                <a href="{{ route('venues.show', $eventVotingVenue->routeIdentifier()) }}">
                                    {{ $eventVotingVenue->name }}
                                </a>
                                @if($eventVotingAddress)
                                    <small>{{ $eventVotingAddress }}</small>
                                @endif
                            @endif
                        </dd>
                    </div>
                @endif
                @if($eventVotingTimeLabel)
                    <div class="coordination-poll-context__item">
                        <dt>Дата и время</dt>
                        <dd>
                            <time datetime="{{ $eventVotingStartsAt->toIso8601String() }}">
                                {{ $eventVotingTimeLabel }}
                            </time>
                        </dd>
                    </div>
                @elseif($eventVotingDate)
                    <div class="coordination-poll-context__item">
                        <dt>Дата</dt>
                        <dd>
                            <time datetime="{{ $eventVotingDate->format('Y-m-d') }}">
                                {{ $eventVotingDate->locale('ru')->translatedFormat('j F') }}
                            </time>
                        </dd>
                    </div>
                @endif
            </dl>
        @endif

        @if($coordination->polls->count() > 1)
            <div class="coordination-flow-progress mb-3" aria-label="Этапы согласования">
                @foreach($coordination->polls as $step)
                    <span class="coordination-flow-progress__step @if($step->id === $poll->id) is-current @elseif($step->decision) is-completed @endif">
                        {{ $step->step_order }}. {{ $step->subject_type->label() }}
                    </span>
                @endforeach
            </div>
        @endif

        <details class="coordination-poll-details">
            <summary>Детали опроса</summary>
            <dl class="coordination-poll-details__list">
                <div class="coordination-poll-context__item">
                    <dt>Тип опроса</dt>
                    <dd>{{ $poll->selection_mode->label() }}</dd>
                </div>
                <div class="coordination-poll-context__item">
                    <dt>Создал опрос</dt>
                    <dd>{{ $organizerName }}</dd>
                </div>
            </dl>
        </details>

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
                                @if($option->proposer)
                                    <small class="coordination-option-proposer">Предложил {{ $option->proposer->profile?->first_name ?: $option->proposer->username }}</small>
                                @endif
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
                @include('theme::pages.coordination.partials.results', compact('poll', 'canSeeResults'))
            @endif
        @else
            @if($isOpen)
                <div class="alert alert-info mt-3">Войдите, чтобы проголосовать.</div>
            @else
                @include('theme::pages.coordination.partials.results', compact('poll', 'canSeeResults'))
            @endif
        @endauth
    </article>

    @auth
        @if($isOpen && $poll->allows_suggestions)
            <section class="section-list-item mt-4">
                <h2 class="h4 mb-2">Предложить вариант</h2>
                <form method="POST" action="{{ route('coordination.suggestion', $coordination) }}" class="coordination-suggestion-form">
                    @csrf
                    @switch($poll->subject_type->value)
                        @case('date')
                            <input class="form-control" type="date" name="option" value="{{ old('option') }}" required>
                            @break
                        @case('time')
                            <input class="form-control" type="time" name="option" value="{{ old('option') }}" required>
                            @break
                        @case('datetime')
                            <input class="form-control" type="datetime-local" name="option" value="{{ old('option') }}" required>
                            @break
                        @case('time_interval')
                            <div class="coordination-suggestion-form__interval">
                                <input class="form-control" type="time" name="option[starts_at]" value="{{ old('option.starts_at') }}" aria-label="Начало интервала" required>
                                <span>—</span>
                                <input class="form-control" type="time" name="option[ends_at]" value="{{ old('option.ends_at') }}" aria-label="Окончание интервала" required>
                            </div>
                            @break
                        @case('venue')
                            <select class="form-select" name="option" required>
                                <option value="">Выберите площадку</option>
                                @foreach($suggestionVenues as $venue)
                                    <option value="{{ $venue->id }}" @selected((string) old('option') === (string) $venue->id)>
                                        {{ $venue->name }} — {{ $venue->raw_address }}
                                    </option>
                                @endforeach
                            </select>
                            @break
                        @default
                            <input class="form-control" name="option" value="{{ old('option') }}" maxlength="255" required>
                    @endswitch
                    @error('option') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    @error('option.*') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    <button class="btn btn--secondary" type="submit">Добавить вариант</button>
                </form>
            </section>
        @endif
    @endauth

    @if($coordination->decision)
        <div class="section-list-item mt-4">
            <span class="badge badge--success">Решение принято</span>
            <div class="coordination-decisions mt-2">
                @foreach($coordination->decisions as $decision)
                    <p class="mb-1">
                        @if($coordination->flow_type->value === 'single')
                            Согласованный вариант: {{ $decision->option->label }}
                        @else
                            <strong>{{ $decision->poll->subject_type->label() }}:</strong>
                            {{ $decision->option->label }}
                        @endif
                    </p>
                @endforeach
            </div>
            @if($coordination->eventTransition?->event)
                <p class="mb-2">По этому решению уже создано мероприятие.</p>
                <a class="btn btn--secondary btn--sm" href="{{ route('events.show', $coordination->eventTransition->event->routeIdentifier()) }}">Открыть мероприятие</a>
            @else
                <p class="mb-0">Итоговый вариант зафиксирован.</p>
            @endif
        </div>
    @endif

    @if($canCreateEvent)
        <section class="section-list-item mt-4">
            <h2 class="h3 mb-2">Создать мероприятие</h2>
            <p>Уточните площадку, время и остальные параметры. Перед созданием система повторно проверит доступность слота и правила бронирования.</p>
            @if($venues->isEmpty())
                <div class="alert alert-info">Нет доступных подтверждённых площадок.</div>
            @else
                @include('theme::pages.events.partials.create-form', [
                    'formAction' => route('coordination.event.store', $coordination),
                    'formIdPrefix' => 'coordinationEvent',
                    'submitLabel' => 'Создать мероприятие',
                    'confirmMessage' => 'Создать мероприятие по принятому решению?',
                    'defaultVenueId' => $coordinatedVenueId,
                    'coordinatedStartsAt' => $coordinatedStartsAt,
                    'coordinatedDuration' => $coordinatedDuration,
                ])
            @endif
        </section>
    @endif

    @if($canApplyEventChange)
        <section class="section-list-item mt-4">
            <h2 class="h3 mb-2">Применить согласованный перенос</h2>
            <p>Площадка и время будут проверены повторно. После переноса прежним участникам потребуется подтвердить участие ещё раз.</p>
            <form method="POST" action="{{ route('coordination.event-change.apply', $coordination) }}">
                @csrf
                <button class="btn btn--primary" type="submit" onclick="return confirm('Перенести мероприятие по согласованному решению?')">
                    Применить к мероприятию
                </button>
            </form>
        </section>
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

    @if($eventVotingHasMap)
        @component('theme::partials.modal.layout', [
            'id' => 'coordination-venue-map',
            'dialogClass' => 'venue-selector-map-modal__dialog event-venue-map-modal__dialog',
        ])
            <h2 class="modal_title" id="modal-title-coordination-venue-map">{{ $eventVotingVenue->name }}</h2>
            <p class="venue-selector-map__message" data-event-map-message>Загружаем карту…</p>
            <div
                class="venue-selector-map"
                data-event-map
                data-yandex-map-api-key="{{ config('integrations.yandex.api_key') }}"
                data-latitude="{{ $eventVotingLatitude }}"
                data-longitude="{{ $eventVotingLongitude }}"
                data-title="{{ $eventVotingVenue->name }}"
                data-address="{{ $eventVotingAddress }}"
                aria-label="Площадка {{ $eventVotingVenue->name }} на карте"
            ></div>
        @endcomponent
    @endif
@endsection
