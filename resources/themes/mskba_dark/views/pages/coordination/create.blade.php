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
    @if(session('error')) <div class="alert alert-danger mb-3">{{ session('error') }}</div> @endif

    <form method="POST" action="{{ route('coordination.store') }}" data-coordination-form>
        @csrf
        <div class="form-group field mb-3">
            <label class="form-label" for="coordinationTitle">Название</label>
            <input id="coordinationTitle" class="form-control @error('title') is-invalid @enderror" name="title" value="{{ old('title') }}" maxlength="150" required>
            @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="form-group field mb-3">
            <label class="form-label" for="coordinationQuestion">Вопрос</label>
            <input id="coordinationQuestion" class="form-control @error('question') is-invalid @enderror" name="question" value="{{ old('question') }}" maxlength="500" required>
            @error('question') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <input type="hidden" name="subject_type" value="text">
        <div class="row g-3 mb-3">
            <div class="col-md-6 form-group field">
                <label class="form-label" for="coordinationSelectionMode">Количество ответов</label>
                <select id="coordinationSelectionMode" class="form-select" name="selection_mode" required>
                    @foreach($selectionModes as $mode)
                        <option value="{{ $mode->value }}" @selected(old('selection_mode', 'single') === $mode->value)>{{ $mode->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6 form-group field">
                <label class="form-label" for="coordinationResultsVisibility">Результаты видны</label>
                <select id="coordinationResultsVisibility" class="form-select" name="results_visibility" required>
                    @foreach($resultsVisibilities as $visibility)
                        <option value="{{ $visibility->value }}" @selected(old('results_visibility', 'after_vote') === $visibility->value)>{{ $visibility->label() }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-md-6 form-group field">
                <label class="form-label" for="coordinationClosesAt">Голосование до</label>
                <input id="coordinationClosesAt" type="datetime-local" class="form-control @error('closes_at') is-invalid @enderror" name="closes_at" value="{{ old('closes_at', $defaultClosesAt) }}" required>
                @error('closes_at') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
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
        </div>
        <div class="row g-3 mb-3">
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
            <div class="coordination-options" data-coordination-options>
                @foreach(old('options', ['', '']) as $option)
                    <div class="coordination-option-row">
                        <input class="form-control" name="options[]" value="{{ $option }}" maxlength="255" required>
                        <button class="btn btn--secondary btn--sm" type="button" data-coordination-option-remove aria-label="Удалить вариант">×</button>
                    </div>
                @endforeach
            </div>
            @error('options') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            @error('options.*') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            <button class="btn btn--secondary btn--sm mt-2" type="button" data-coordination-option-add>Добавить вариант</button>
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
                        <label class="form-check">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="telegram_chat_ids[]"
                                value="{{ $chat->id }}"
                                @checked(in_array((string) $chat->id, $selectedTelegramChatIds, true))
                            >
                            <span class="form-check-label">{{ $chat->title ?: 'Чат '.$chat->telegram_chat_id }}</span>
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
