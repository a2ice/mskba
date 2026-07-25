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
        $selectedFlowType = old('flow_type', $contextEvent ? 'event_scheduling' : 'single');
        $oldOptions = old('options', ['', '']);
        $venueEditorOptions = $optionVenues->map(fn ($venue) => [
            'id' => $venue->id,
            'label' => $venue->name.' — '.$venue->raw_address,
        ])->values();
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
            <select id="coordinationFlowType" class="form-select" name="flow_type" data-coordination-flow-type required @disabled($contextEvent)>
                @foreach($flowTypes as $flowType)
                    <option value="{{ $flowType->value }}" @selected($selectedFlowType === $flowType->value)>{{ $flowType->label() }}</option>
                @endforeach
            </select>
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
            <div class="col-md-6 form-group field">
                <input type="hidden" name="allows_vote_changes" value="0">
                <label class="coordination-setting-toggle">
                    <input
                        class="coordination-setting-toggle__input"
                        type="checkbox"
                        name="allows_vote_changes"
                        value="1"
                        @checked((bool) old('allows_vote_changes', false))
                    >
                    <span class="coordination-setting-toggle__control" aria-hidden="true"></span>
                    <strong class="coordination-setting-toggle__title">Разрешить менять голос</strong>
                    <small class="coordination-setting-toggle__description">До закрытия опроса участник сможет выбрать другой ответ</small>
                </label>
                @error('allows_vote_changes') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-6 form-group field">
                <input type="hidden" name="is_anonymous" value="0">
                <label class="coordination-setting-toggle">
                    <input
                        class="coordination-setting-toggle__input"
                        type="checkbox"
                        name="is_anonymous"
                        value="1"
                        @checked((bool) old('is_anonymous', false))
                    >
                    <span class="coordination-setting-toggle__control" aria-hidden="true"></span>
                    <strong class="coordination-setting-toggle__title">Анонимный опрос</strong>
                    <small class="coordination-setting-toggle__description">Показывать количество голосов без имён участников</small>
                </label>
                @error('is_anonymous') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            </div>
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
        <div class="form-group field mb-3">
            <input type="hidden" name="allows_suggestions" value="0">
            <label class="coordination-setting-toggle">
                <input
                    class="coordination-setting-toggle__input"
                    type="checkbox"
                    name="allows_suggestions"
                    value="1"
                    @checked((bool) old('allows_suggestions', false))
                >
                <span class="coordination-setting-toggle__control" aria-hidden="true"></span>
                <strong class="coordination-setting-toggle__title">Разрешить свои варианты</strong>
                <small class="coordination-setting-toggle__description">Участники смогут добавить вариант того же типа до закрытия опроса</small>
            </label>
            @error('allows_suggestions') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
        </div>
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
                <input type="hidden" name="publish_to_telegram" value="0">
                <label class="coordination-setting-toggle">
                    <input
                        class="coordination-setting-toggle__input"
                        type="checkbox"
                        name="publish_to_telegram"
                        value="1"
                        @checked((bool) old('publish_to_telegram', true))
                    >
                    <span class="coordination-setting-toggle__control" aria-hidden="true"></span>
                    <strong class="coordination-setting-toggle__title">Опубликовать в Telegram</strong>
                    <small class="coordination-setting-toggle__description">Опрос появится в выбранных чатах, ответы будут синхронизироваться с порталом</small>
                </label>
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
@endsection
