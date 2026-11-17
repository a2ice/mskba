@php
    $title = 'Новое мероприятие';
    $oldType = old('type', $defaultType->value);
@endphp
@extends('theme::layouts.app', ['title' => $title])
@section('content')
<section class="app-account-section">
    <div class="container app-account-section__inner">
        <header class="app-account-section__heading">
            <p><a href="{{ route('events.index') }}">← Все мероприятия</a></p>
            <h1>Создать мероприятие</h1>
            <p>Сначала организуйте игру или тренировку и набор участников, а зал забронируйте позже.</p>
        </header>
        @if(session('error'))<section class="panel" role="alert"><p>{{ session('error') }}</p></section>@endif
        @if($errors->any())
            <section class="panel" role="alert">
                <h2>Проверьте данные</h2>
                @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
            </section>
        @endif
        <form method="POST" action="{{ route('events.store') }}" class="panel" data-app-event-create>
            @csrf
            <input type="hidden" name="event_request_id" value="{{ old('event_request_id', $eventRequestId) }}">
            <div class="field-group">
                <label for="eventType">Тип мероприятия</label>
                <span class="select-control">
                    <select id="eventType" name="type" required data-event-type>
                        @foreach($types as $type)
                            <option value="{{ $type->value }}" @selected($oldType === $type->value)>{{ $type->label() }}</option>
                        @endforeach
                    </select>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </span>
            </div>
            <div class="field-group">
                <label for="eventTitle">Название</label>
                <input id="eventTitle" name="title" value="{{ old('title', $defaultTitle) }}" maxlength="150" required>
            </div>
            <div class="field-group">
                <label for="eventStart">Дата и время</label>
                <input id="eventStart" type="datetime-local" name="starts_at"
                    value="{{ old('starts_at', $defaultStartsAt) }}" min="{{ $minimumStartsAt }}" required>
            </div>
            <div class="field-group">
                <label for="eventDuration">Продолжительность</label>
                <span class="select-control">
                    <select id="eventDuration" name="duration_minutes" required>
                        @foreach($durationOptions as $minutes)
                            <option value="{{ $minutes }}" @selected((int) old('duration_minutes', $defaultDuration) === $minutes)>{{ $minutes }} мин.</option>
                        @endforeach
                    </select>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </span>
            </div>
            <div class="field-group">
                <label for="eventVenue">Площадка</label>
                <span class="select-control">
                    <select id="eventVenue" name="venue_id" required data-event-venue>
                        <option value="" disabled @selected(!old('venue_id'))>Выберите площадку</option>
                        @foreach($venues as $venue)
                            <option value="{{ $venue->id }}" @selected((string) old('venue_id') === (string) $venue->id)>
                                {{ $venue->name }}{{ $venue->raw_address ? ' — '.$venue->raw_address : '' }}
                            </option>
                        @endforeach
                    </select>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </span>
            </div>
            <div class="field-group">
                <label for="eventCourt">Зал</label>
                <span class="select-control">
                    <select id="eventCourt" name="venue_court_id" data-event-court>
                        <option value="">Основной зал площадки</option>
                        @foreach($venues as $venue)
                            @foreach($venue->courts as $court)
                                <option value="{{ $court->id }}" data-venue-id="{{ $venue->id }}"
                                    @selected((string) old('venue_court_id') === (string) $court->id)>{{ $court->name }}</option>
                            @endforeach
                        @endforeach
                    </select>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </span>
            </div>
            <div class="field-group">
                <label for="eventScope">Планируемая аренда</label>
                <span class="select-control">
                    <select id="eventScope" name="booking_scope" required>
                        @foreach(['whole' => 'Весь зал', 'half_a' => 'Половина A', 'half_b' => 'Половина B'] as $scope => $label)
                            <option value="{{ $scope }}" @selected(old('booking_scope', 'whole') === $scope)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </span>
                <p>Половина доступна только для залов, поддерживающих раздельное бронирование.</p>
            </div>
            <div data-app-game-settings>
                <h2>Параметры игры</h2>
                <div class="field-group">
                    <label for="gameFormat">Формат игры</label>
                    <span class="select-control">
                        <select id="gameFormat" name="game_format" data-game-format>
                            @foreach(\App\Modules\Event\Domain\Enums\GameFormatEnum::cases() as $format)
                                <option value="{{ $format->value }}" @selected(old('game_format', 'basketball_5x5') === $format->value)>{{ $format->label() }}</option>
                            @endforeach
                        </select>
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </span>
                </div>
                <div class="field-group">
                    <label for="sideA">Игроков в стороне A</label>
                    <input id="sideA" type="number" min="1" max="7" name="side_a_size" value="{{ old('side_a_size', 5) }}" data-side-a>
                </div>
                <div class="field-group">
                    <label for="sideB">Игроков в стороне B</label>
                    <input id="sideB" type="number" min="1" max="7" name="side_b_size" value="{{ old('side_b_size', 5) }}" data-side-b>
                </div>
                <div class="field-group">
                    <label for="scoringType">Счёт</label>
                    <span class="select-control">
                        <select id="scoringType" name="scoring_type" data-scoring-type>
                            @foreach(\App\Modules\Event\Domain\Enums\GameScoringTypeEnum::cases() as $type)
                                <option value="{{ $type->value }}" @selected(old('scoring_type', 'basketball') === $type->value)>{{ $type->label() }}</option>
                            @endforeach
                        </select>
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </span>
                </div>
                <div class="field-group">
                    <label for="timingMode">Режим времени</label>
                    <span class="select-control">
                        <select id="timingMode" name="timing_mode" data-timing-mode>
                            @foreach(\App\Modules\Event\Domain\Enums\GameTimingModeEnum::cases() as $mode)
                                <option value="{{ $mode->value }}" @selected(old('timing_mode', 'periods') === $mode->value)>{{ $mode->label() }}</option>
                            @endforeach
                        </select>
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </span>
                </div>
                <div class="field-group" data-periods-field>
                    <label for="periodsCount">Периодов</label>
                    <span class="select-control">
                        <select id="periodsCount" name="periods_count">
                            <option value="4" @selected((int) old('periods_count', 4) === 4)>4 периода</option>
                            <option value="2" @selected((int) old('periods_count', 4) === 2)>2 периода</option>
                        </select>
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </span>
                </div>
                <div class="field-group">
                    <label for="recruitmentMode">Набор участников</label>
                    <span class="select-control">
                        <select id="recruitmentMode" name="game_recruitment_mode">
                            @foreach(\App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum::cases() as $mode)
                                <option value="{{ $mode->value }}" @selected(old('game_recruitment_mode', 'preformed_teams') === $mode->value)>{{ $mode->label() }}</option>
                            @endforeach
                        </select>
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </span>
                </div>
                <input type="hidden" name="game_accepts_applications" value="0">
                <label class="check-label">
                    <input type="checkbox" name="game_accepts_applications" value="1"
                        @checked(old('game_accepts_applications', '1') === '1')>
                    <span>Разрешить заявки на участие</span>
                </label>
            </div>
            <div class="field-group">
                <label for="eventCapacity">Количество участников</label>
                <input id="eventCapacity" type="number" min="2" max="500" name="max_participants"
                    value="{{ old('max_participants') }}" placeholder="Без ограничения">
            </div>
            <div class="field-group">
                <label for="eventVisibility">Кому видно</label>
                <span class="select-control">
                    <select id="eventVisibility" name="visibility" required>
                        @foreach($visibilities as $visibility)
                            <option value="{{ $visibility->value }}" @selected(old('visibility', 'public') === $visibility->value)>{{ $visibility->label() }}</option>
                        @endforeach
                    </select>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </span>
            </div>
            <div class="field-group">
                <label for="eventDescription">Описание</label>
                <textarea id="eventDescription" rows="4" maxlength="5000" name="description">{{ old('description') }}</textarea>
            </div>
            <h2>Планирование аренды</h2>
            <p>Если у площадки предусмотрена аренда, мероприятие будет опубликовано без бронирования. Предзаявка — необязательный сигнал площадке для планирования и переписки, она не занимает слот.</p>
            <input type="hidden" name="send_pre_request" value="0">
            <label class="check-label">
                <input type="checkbox" name="send_pre_request" value="1"
                    @checked((string) old('send_pre_request', '1') === '1')>
                <span>Сообщить площадке о предварительном интересе</span>
            </label>
            <div class="app-rental-pricing__actions">
                <button type="submit" class="button primary">Создать мероприятие</button>
                <a class="button secondary" href="{{ route('events.index') }}">Вернуться</a>
            </div>
        </form>
    </div>
</section>
@endsection
@push('scripts')
<script>
(() => {
    const root = document.querySelector('[data-app-event-create]');
    if (!root) return;
    const venue = root.querySelector('[data-event-venue]');
    const court = root.querySelector('[data-event-court]');
    const type = root.querySelector('[data-event-type]');
    const game = root.querySelector('[data-app-game-settings]');
    const format = root.querySelector('[data-game-format]');
    const timing = root.querySelector('[data-timing-mode]');
    const periods = root.querySelector('[data-periods-field]');
    const selectedCourt = court.value;
    function syncCourts() {
        for (const option of court.options) {
            if (!option.value) continue;
            const available = option.dataset.venueId === venue.value;
            option.disabled = !available;
            option.hidden = !available;
        }
        if (court.selectedOptions[0]?.disabled) court.value = '';
    }
    function syncType() {
        game.hidden = type.value !== 'game';
    }
    function syncTiming() {
        periods.hidden = timing.value !== 'periods';
    }
    function syncFormat() {
        const presets = {
            basketball_5x5: {count: 5, scoring: 'basketball', timing: 'periods'},
            streetball_3x3: {count: 3, scoring: 'streetball', timing: 'whole_game'},
            streetball_1x1: {count: 1, scoring: 'streetball', timing: 'whole_game'}
        };
        const preset = presets[format.value];
        if (!preset) return;
        root.querySelector('[data-side-a]').value = preset.count;
        root.querySelector('[data-side-b]').value = preset.count;
        root.querySelector('[data-scoring-type]').value = preset.scoring;
        timing.value = preset.timing;
        syncTiming();
    }
    venue.addEventListener('change', syncCourts);
    type.addEventListener('change', syncType);
    format.addEventListener('change', syncFormat);
    timing.addEventListener('change', syncTiming);
    syncCourts();
    if (selectedCourt && !court.querySelector('option[value="' + selectedCourt + '"]')?.disabled) court.value = selectedCourt;
    syncType();
    syncTiming();
})();
</script>
@endpush
