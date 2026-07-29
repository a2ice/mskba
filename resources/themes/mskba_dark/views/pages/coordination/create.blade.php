@php $title = 'Новый опрос'; @endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'coordination',
    'sectionClass' => 'coordination-section',
    'contentTitle' => $title,
    'contentSubtitle' => 'После закрытия голосования создатель отдельно принимает итоговый вариант.',
    'sidebarLabel' => 'Навигация опросов',
])

@section('section-sidebar')
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Опросы</h2>
        <ul class="sidebar-nav nav flex-column">
            <li class="nav-item"><a class="nav-link" href="{{ route('coordination.index') }}">Все опросы</a></li>
            <li class="nav-item active"><a class="nav-link active" href="{{ route('coordination.create') }}">Создать</a></li>
        </ul>
    </div>
@endsection

@section('section-content')
    @php
        $selectedSubjectType = old('subject_type', 'text');
        $selectedFlowType = old('flow_type', $contextEvent ? 'event_scheduling' : 'event_attendance');
        $oldOptions = old('options', ['', '']);
        $venueEditorOptions = $optionVenues->map(fn ($venue) => [
            'id' => $venue->id,
            'label' => $venue->name.' — '.$venue->raw_address,
        ])->values();
        $selectedFixedVenueId = old('fixed_venue_id');
        $selectedFixedVenue = $selectedFixedVenueId
            ? $optionVenues->firstWhere('id', (int) $selectedFixedVenueId)
            : null;
    @endphp
    @if(session('error')) <div class="alert alert-danger mb-3">{{ session('error') }}</div> @endif

    <form method="POST" action="{{ route('coordination.store') }}" data-coordination-form>
        @csrf
        @if($contextEvent)
            <input type="hidden" name="context_event_id" value="{{ $contextEvent->id }}">
            <div class="alert alert-info mb-3">
                Согласование переноса мероприятия «{{ $contextEvent->title }}». После выбора всех этапов примените решение на странице опроса.
            </div>
        @endif
        <div class="form-group field mb-3">
            <label class="form-label" for="coordinationTitle">Название</label>
            <input id="coordinationTitle" class="form-control @error('title') is-invalid @enderror" name="title" value="{{ old('title', $contextEvent ? 'Перенос: '.$contextEvent->title : '') }}" maxlength="150" required>
            @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="form-group field mb-3">
            <label class="form-label" for="coordinationFlowType">Сценарий</label>
            <div class="coordination-flow-picker">
                <select id="coordinationFlowType" class="form-select" name="flow_type" data-coordination-flow-type required @disabled($contextEvent)>
                    @foreach($flowTypes as $flowType)
                        <option value="{{ $flowType->value }}" @selected($selectedFlowType === $flowType->value)>{{ $flowType->label() }}</option>
                    @endforeach
                </select>
                <button
                    type="button"
                    class="ui-tooltip-trigger coordination-flow-picker__help"
                    aria-label="Описание сценариев опроса"
                    data-tooltip="Сбор участников — площадка и время известны; участники отвечают, пойдут ли.
Простой опрос — произвольный вопрос и варианты ответа.
Выбрать время — площадка и дата известны; выбирается время начала.
Выбрать площадку — дата и время известны; выбирается площадка.
Дата, время и площадка — последовательное согласование всех трёх параметров."
                >?</button>
            </div>
            @if($contextEvent)<input type="hidden" name="flow_type" value="event_scheduling">@endif
        </div>
        <div data-coordination-single-flow @if($selectedFlowType !== 'single') hidden @endif>
        <div class="form-group field mb-3">
            <label class="form-label" for="coordinationQuestion">Вопрос</label>
            <input id="coordinationQuestion" class="form-control @error('question') is-invalid @enderror" name="question" value="{{ old('question') }}" maxlength="500" required>
            @error('question') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="row g-3 mb-3">
            <div class="col-md-6 form-group field">
                <label class="form-label" for="coordinationSubjectType">Тип вариантов</label>
                <select id="coordinationSubjectType" class="form-select" name="subject_type" data-coordination-subject-type required>
                    @foreach($subjectTypes as $subjectType)
                        <option value="{{ $subjectType->value }}" @selected($selectedSubjectType === $subjectType->value)>{{ $subjectType->label() }}</option>
                    @endforeach
                </select>
                @error('subject_type') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-6 form-group field">
                <label class="form-label" for="coordinationSelectionMode">Количество ответов</label>
                <select id="coordinationSelectionMode" class="form-select" name="selection_mode" required>
                    @foreach($selectionModes as $mode)
                        <option value="{{ $mode->value }}" @selected(old('selection_mode', 'single') === $mode->value)>{{ $mode->label() }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-md-6 form-group field">
                <label class="form-label" for="coordinationResultsVisibility">Результаты видны</label>
                <select id="coordinationResultsVisibility" class="form-select" name="results_visibility" required>
                    @foreach($resultsVisibilities as $visibility)
                        <option value="{{ $visibility->value }}" @selected(old('results_visibility', 'after_vote') === $visibility->value)>{{ $visibility->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6 form-group field">
                <label class="form-label" for="coordinationClosesAt">Голосование до</label>
                <input id="coordinationClosesAt" type="datetime-local" class="form-control @error('closes_at') is-invalid @enderror" name="closes_at" value="{{ old('closes_at', $defaultClosesAt) }}" required>
                @error('closes_at') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>
        <div class="row g-3 mb-3">
            @include('theme::partials.forms.toggle', [
                'name' => 'allows_vote_changes',
                'id' => 'coordinationAllowsVoteChanges',
                'title' => 'Разрешить менять голос',
                'description' => 'До закрытия опроса участник сможет выбрать другой ответ',
                'checked' => old('allows_vote_changes', false),
                'wrapperClass' => 'col-md-6 form-group field',
            ])
            @include('theme::partials.forms.toggle', [
                'name' => 'is_anonymous',
                'id' => 'coordinationIsAnonymous',
                'title' => 'Анонимный опрос',
                'description' => 'Показывать количество голосов без имён участников',
                'checked' => old('is_anonymous', false),
                'wrapperClass' => 'col-md-6 form-group field',
            ])
        </div>
        <div class="form-group field mb-3">
            <label class="form-label">Варианты ответа</label>
            <div class="coordination-options" data-coordination-options data-subject-type="{{ $selectedSubjectType }}">
                @foreach($oldOptions as $index => $option)
                    @include('theme::pages.coordination.partials.option-editor-row', [
                        'index' => $index,
                        'subjectType' => $selectedSubjectType,
                        'option' => $option,
                    ])
                @endforeach
            </div>
            @error('options') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            @error('options.*') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            <button class="btn btn--secondary btn--sm mt-2" type="button" data-coordination-option-add>Добавить вариант</button>
            <script type="application/json" data-coordination-venue-options>@json($venueEditorOptions)</script>
        </div>
        </div>
        <div data-coordination-event-poll-flow @if(!in_array($selectedFlowType, ['event_attendance', 'event_time_selection', 'event_venue_selection'], true)) hidden @endif>
            <div class="alert alert-info mb-3">
                Заданные площадка и время проверяются сейчас и повторно перед созданием мероприятия.
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6 form-group field">
                    <label class="form-label" for="eventPollResultsVisibility">Результаты видны</label>
                    <select id="eventPollResultsVisibility" class="form-select" name="results_visibility">
                        @foreach($resultsVisibilities as $visibility)
                            <option value="{{ $visibility->value }}" @selected(old('results_visibility', 'after_vote') === $visibility->value)>{{ $visibility->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6 form-group field">
                    <label class="form-label" for="eventPollClosesAt">Голосование до</label>
                    <input id="eventPollClosesAt" type="datetime-local" class="form-control" name="closes_at" value="{{ old('closes_at', $defaultClosesAt) }}">
                </div>
            </div>

            <div data-coordination-event-flow="event_attendance" @if($selectedFlowType !== 'event_attendance') hidden @endif>
                <div class="row g-3 mb-3">
                    <div class="col-md-6 form-group field">
                        @include('theme::partials.venues.predictive-selector', [
                            'id' => 'attendanceVenue',
                            'name' => 'fixed_venue_id',
                            'selectedVenue' => $selectedFixedVenue,
                            'confirmedOnly' => true,
                            'operationalStatus' => 'active',
                            'startInput' => '#attendanceStartsAt',
                            'durationInput' => '#attendanceDuration',
                            'mapModal' => 'attendance-venue-map',
                            'showFavorites' => true,
                        ])
                    </div>
                    <div class="col-md-6 form-group field">
                        <label class="form-label" for="attendanceStartsAt">Дата и время</label>
                        <input id="attendanceStartsAt" class="form-control" type="datetime-local" name="fixed_starts_at" value="{{ old('fixed_starts_at', now()->addDay()->setTime(19, 0)->format('Y-m-d\TH:i')) }}">
                    </div>
                </div>
                <div class="form-group field mb-3">
                    <label class="form-label" for="attendanceDuration">Длительность</label>
                    <select id="attendanceDuration" class="form-select" name="event_duration_minutes">
                        <option value="" @selected(old('event_duration_minutes') === null || old('event_duration_minutes') === '')>Автоматически</option>
                        @foreach(range(30, 480, 30) as $minutes)
                            <option value="{{ $minutes }}" @selected((string) old('event_duration_minutes') === (string) $minutes)>{{ $minutes < 60 ? $minutes.' минут' : rtrim(rtrim(number_format($minutes / 60, 1, ',', ''), '0'), ',').' ч' }}</option>
                        @endforeach
                    </select>
                    <small class="form-text">Если не указывать длительность, она будет рассчитана до ближайшего ограничения: конца дня, окончания работы площадки или следующего занятого слота.</small>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6 form-group field">
                        <label class="form-label" for="goingLabel">Положительное решение</label>
                        <input id="goingLabel" class="form-control" name="going_label" value="{{ old('going_label', 'Пойду') }}" maxlength="255">
                        <small class="form-text">🟢 В системе фиксируется как намерение прийти</small>
                    </div>
                    <div class="col-md-6 form-group field">
                        <label class="form-label" for="notGoingLabel">Отрицательное решение</label>
                        <input id="notGoingLabel" class="form-control" name="not_going_label" value="{{ old('not_going_label', 'Не пойду') }}" maxlength="255">
                        <small class="form-text">🔴 В системе фиксируется как отказ</small>
                    </div>
                </div>
                @include('theme::partials.forms.toggle', [
                    'name' => 'include_thinking_option',
                    'id' => 'includeThinkingOption',
                    'title' => 'Добавить вариант «Думаю»',
                    'checked' => old('include_thinking_option', false),
                    'inputAttributes' => ['data-coordination-thinking-toggle' => true],
                ])
                <div class="form-group field mb-3" data-coordination-thinking-label @if(!old('include_thinking_option')) hidden @endif>
                    <label class="form-label" for="thinkingLabel">Раздумывает</label>
                    <input id="thinkingLabel" class="form-control" name="thinking_label" value="{{ old('thinking_label', 'Думаю') }}" maxlength="255">
                    <small class="form-text">🟡 В системе фиксируется как раздумье</small>
                </div>
                <p class="form-text mb-3">
                    Если разрешить свои варианты, ответы вроде «Опоздаю на 15 минут» останутся отдельными пояснениями.
                    При создании мероприятия вы сами решите, учитывать ли проголосовавшего как участника.
                </p>
            </div>

            <div data-coordination-event-flow="event_time_selection" @if($selectedFlowType !== 'event_time_selection') hidden @endif>
                <div class="row g-3 mb-3">
                    <div class="col-md-6 form-group field">
                        @include('theme::partials.venues.predictive-selector', [
                            'id' => 'timeVenue',
                            'name' => 'fixed_venue_id',
                            'selectedVenue' => $selectedFixedVenue,
                            'confirmedOnly' => true,
                            'operationalStatus' => 'active',
                            'durationInput' => '#timeDuration',
                            'mapModal' => 'time-venue-map',
                            'showFavorites' => true,
                        ])
                    </div>
                    <div class="col-md-3 form-group field">
                        <label class="form-label" for="timeDate">Дата</label>
                        <input id="timeDate" class="form-control" type="date" name="fixed_date" value="{{ old('fixed_date', now()->addDay()->format('Y-m-d')) }}">
                    </div>
                    <div class="col-md-3 form-group field">
                        <label class="form-label" for="timeDuration">Длительность</label>
                        <select id="timeDuration" class="form-select" name="event_duration_minutes">
                            <option value="" @selected(old('event_duration_minutes') === null || old('event_duration_minutes') === '')>Автоматически</option>
                            @foreach(range(30, 480, 30) as $minutes)
                                <option value="{{ $minutes }}" @selected((string) old('event_duration_minutes') === (string) $minutes)>{{ $minutes }} мин.</option>
                            @endforeach
                        </select>
                        <small class="form-text">Без длительности каждый вариант проверяется до ближайшего ограничения площадки.</small>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    @foreach(old('start_time_options', ['18:00', '19:00']) as $index => $time)
                        <div class="col-md-6 form-group field">
                            <label class="form-label" for="startTime{{ $index }}">Время начала {{ $index + 1 }}</label>
                            <input id="startTime{{ $index }}" class="form-control" type="time" name="start_time_options[]" value="{{ $time }}">
                        </div>
                    @endforeach
                </div>
            </div>

            <div data-coordination-event-flow="event_venue_selection" @if($selectedFlowType !== 'event_venue_selection') hidden @endif>
                <div class="row g-3 mb-3">
                    <div class="col-md-6 form-group field">
                        <label class="form-label" for="venueStartsAt">Дата и время</label>
                        <input id="venueStartsAt" class="form-control" type="datetime-local" name="fixed_starts_at" value="{{ old('fixed_starts_at', now()->addDay()->setTime(19, 0)->format('Y-m-d\TH:i')) }}">
                    </div>
                    <div class="col-md-6 form-group field">
                        <label class="form-label" for="venueDuration">Длительность</label>
                        <select id="venueDuration" class="form-select" name="event_duration_minutes">
                            <option value="" @selected(old('event_duration_minutes') === null || old('event_duration_minutes') === '')>Автоматически</option>
                            @foreach(range(30, 480, 30) as $minutes)
                                <option value="{{ $minutes }}" @selected((string) old('event_duration_minutes') === (string) $minutes)>{{ $minutes }} мин.</option>
                            @endforeach
                        </select>
                        <small class="form-text">Без длительности для выбранной площадки будет использовано ближайшее ограничение.</small>
                    </div>
                </div>
                <fieldset class="form-group field mb-3">
                    <legend class="form-label">Площадки-кандидаты</legend>
                    <div class="coordination-chain-venues">
                        @foreach($optionVenues as $venue)
                            <label class="coordination-checkbox">
                                <input class="coordination-checkbox__input" type="checkbox" name="candidate_venue_ids[]" value="{{ $venue->id }}" @checked(in_array((string) $venue->id, array_map('strval', old('candidate_venue_ids', [])), true))>
                                <span class="coordination-checkbox__control" aria-hidden="true"></span>
                                <span class="coordination-checkbox__label">{{ $venue->name }} — {{ $venue->raw_address }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            </div>

            @include('theme::partials.forms.toggle', [
                'name' => 'allows_vote_changes',
                'id' => 'eventPollAllowsVoteChanges',
                'title' => 'Разрешить менять голос',
                'checked' => old('allows_vote_changes', false),
            ])
            <input type="hidden" name="is_anonymous" value="0">
        </div>
        <div class="coordination-chain-editor mb-4" data-coordination-chain-flow @if($selectedFlowType !== 'event_scheduling') hidden @endif>
            <div class="alert alert-info mb-3">
                Сначала участники выберут дату, затем время. Перед финальным голосованием система оставит только свободные площадки.
            </div>
            <div class="form-group field mb-3">
                <label class="form-label" for="coordinationStepDuration">Время на каждый следующий этап</label>
                <select id="coordinationStepDuration" class="form-select" name="step_duration_minutes">
                    @foreach([15 => '15 минут', 30 => '30 минут', 60 => '1 час', 120 => '2 часа', 240 => '4 часа', 480 => '8 часов', 1440 => '1 день'] as $minutes => $label)
                        <option value="{{ $minutes }}" @selected((int) old('step_duration_minutes', 60) === $minutes)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="row g-3 mb-3">
                @foreach(old('date_options', $defaultDates) as $index => $date)
                    <div class="col-md-6 form-group field">
                        <label class="form-label" for="coordinationDate{{ $index }}">Вариант даты {{ $index + 1 }}</label>
                        <input id="coordinationDate{{ $index }}" class="form-control" type="date" name="date_options[]" value="{{ $date }}">
                    </div>
                @endforeach
            </div>
            <div class="row g-3 mb-3">
                @foreach(old('time_options', $defaultTimes) as $index => $interval)
                    <div class="col-md-6 form-group field">
                        <label class="form-label">Вариант времени {{ $index + 1 }}</label>
                        <div class="coordination-option-interval">
                            <input class="form-control" type="time" name="time_options[{{ $index }}][starts_at]" value="{{ $interval['starts_at'] ?? '' }}">
                            <span>—</span>
                            <input class="form-control" type="time" name="time_options[{{ $index }}][ends_at]" value="{{ $interval['ends_at'] ?? '' }}">
                        </div>
                    </div>
                @endforeach
            </div>
            <fieldset class="form-group field">
                <legend class="form-label">Площадки-кандидаты</legend>
                <div class="coordination-chain-venues">
                    @foreach($optionVenues as $venue)
                        <label class="coordination-checkbox">
                            <input
                                class="coordination-checkbox__input"
                                type="checkbox"
                                name="venue_options[]"
                                value="{{ $venue->id }}"
                                @checked(in_array((string) $venue->id, array_map('strval', old('venue_options', [])), true))
                            >
                            <span class="coordination-checkbox__control" aria-hidden="true"></span>
                            <span class="coordination-checkbox__label">{{ $venue->name }} — {{ $venue->raw_address }}</span>
                        </label>
                    @endforeach
                </div>
                @error('venue_options') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            </fieldset>
        </div>
        @include('theme::partials.forms.toggle', [
            'name' => 'allows_suggestions',
            'id' => 'coordinationAllowsSuggestions',
            'title' => 'Разрешить свои варианты',
            'description' => 'Участники смогут добавить вариант того же типа до закрытия опроса',
            'checked' => old('allows_suggestions', false),
        ])
        <div class="form-group field mb-4">
            <label class="form-label" for="coordinationDescription">Описание</label>
            <textarea id="coordinationDescription" class="form-control" name="description" rows="4" maxlength="5000">{{ old('description') }}</textarea>
        </div>
        @if($telegramChats->isNotEmpty())
            @php
                $defaultTelegramChatIds = $telegramChats->pluck('id')->map(fn ($id) => (string) $id)->all();
                $selectedTelegramChatIds = array_map('strval', old('telegram_chat_ids', $defaultTelegramChatIds));
            @endphp
            <div class="form-group field mb-4">
                @include('theme::partials.forms.toggle', [
                    'name' => 'publish_to_telegram',
                    'id' => 'coordinationPublishToTelegram',
                    'title' => 'Опубликовать в Telegram',
                    'description' => 'Опрос появится в выбранных чатах, ответы будут синхронизироваться с порталом',
                    'checked' => old('publish_to_telegram', true),
                    'wrapperClass' => '',
                ])
                <div class="coordination-telegram-chats mt-3">
                    @foreach($telegramChats as $chat)
                        <label class="coordination-checkbox">
                            <input
                                class="coordination-checkbox__input"
                                type="checkbox"
                                name="telegram_chat_ids[]"
                                value="{{ $chat->id }}"
                                @checked(in_array((string) $chat->id, $selectedTelegramChatIds, true))
                            >
                            <span class="coordination-checkbox__control" aria-hidden="true"></span>
                            <span class="coordination-checkbox__label">{{ $chat->title ?: 'Чат '.$chat->telegram_chat_id }}</span>
                        </label>
                    @endforeach
                </div>
                @error('telegram_chat_ids') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                @error('telegram_chat_ids.*') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            </div>
        @else
            <input type="hidden" name="publish_to_telegram" value="0">
        @endif
        <button class="btn btn--primary" type="submit">Открыть голосование</button>
    </form>

    @component('theme::partials.modal.layout', ['id' => 'event-favorite-venues'])
        <h2 class="modal_title" id="modal-title-event-favorite-venues">Избранные площадки</h2>
        <p class="modal-description">Функционал находится в разработке.</p>
    @endcomponent
@endsection
