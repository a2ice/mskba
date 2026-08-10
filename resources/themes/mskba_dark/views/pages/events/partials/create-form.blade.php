@php
    $formIdPrefix = $formIdPrefix ?? 'event';
    $submitLabel = $submitLabel ?? 'Создать мероприятие';
    $selectedVenueId = old('venue_id', $defaultVenueId ?? null);
    $selectedVenue = $selectedVenueId
        ? $venues->firstWhere('id', (int) $selectedVenueId)
        : null;
    $selectedDuration = (int) old('duration_minutes', $coordinatedDuration ?? $defaultDuration);
    $selectedScoringType = old(
        'scoring_type',
        \App\Modules\Event\Domain\Enums\GameScoringTypeEnum::STREETBALL->value,
    );
    $selectedGameFormat = old('game_format', \App\Modules\Event\Domain\Enums\GameFormatEnum::STREETBALL_3X3->value);
    $selectedTimingMode = old('timing_mode', $selectedGameFormat === \App\Modules\Event\Domain\Enums\GameFormatEnum::BASKETBALL_5X5->value ? 'periods' : 'whole_game');
    $defaultSideSize = \App\Modules\Event\Domain\Enums\GameFormatEnum::tryFrom($selectedGameFormat)?->sideSize()
        ?? ($selectedScoringType === \App\Modules\Event\Domain\Enums\GameScoringTypeEnum::BASKETBALL->value ? 5 : 3);
    $displayDurationOptions = collect($durationOptions)
        ->when(
            $selectedDuration > 0 && !in_array($selectedDuration, $durationOptions, true),
            fn ($options) => $options->push($selectedDuration),
        )
        ->sort()
        ->values();
@endphp

<form method="POST" action="{{ $formAction }}" data-event-create-form data-current-date="{{ $currentDate }}">
    @csrf
    <div class="form-group field mb-3">
        <label class="form-label" for="{{ $formIdPrefix }}Title">Название</label>
        <input id="{{ $formIdPrefix }}Title" class="form-control @error('title') is-invalid @enderror" name="title" value="{{ old('title', $defaultTitle) }}" maxlength="150" required data-event-title data-generated-title="{{ $defaultTitle }}">
        @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="form-group field mb-3">
        <label class="form-label" for="{{ $formIdPrefix }}Type">Тип</label>
        <select id="{{ $formIdPrefix }}Type" class="form-select @error('type') is-invalid @enderror" name="type" required>
            @foreach($types as $type)<option value="{{ $type->value }}" data-title-prefix="{{ $type->label() }}" @selected(old('type', $defaultType->value) === $type->value)>{{ $type->label() }}</option>@endforeach
        </select>
    </div>
    @if(isset($teams))
        <fieldset class="form-group field mb-4" data-game-team-fields @if(old('type', $defaultType->value) !== \App\Modules\Event\Domain\Enums\EventTypeEnum::GAME->value) hidden @endif>
            <legend class="form-label">Команды и формат игры</legend>
            <p class="form-text mb-3">Выберите две постоянные команды. Текущий активный состав будет сохранён как снимок этой игры и его можно будет скорректировать перед началом.</p>
            <div class="form-group field mb-3"><label class="form-label" for="{{ $formIdPrefix }}GameFormat">Предустановка формата</label><select id="{{ $formIdPrefix }}GameFormat" class="form-select" name="game_format" data-game-format>@foreach(\App\Modules\Event\Domain\Enums\GameFormatEnum::cases() as $format)<option value="{{ $format->value }}" data-side-size="{{ $format->sideSize() }}" data-scoring-type="{{ $format->scoringType()?->value }}" data-timing-mode="{{ $format === \App\Modules\Event\Domain\Enums\GameFormatEnum::BASKETBALL_5X5 ? 'periods' : 'whole_game' }}" data-periods-count="{{ $format === \App\Modules\Event\Domain\Enums\GameFormatEnum::BASKETBALL_5X5 ? 4 : '' }}" @selected($selectedGameFormat === $format->value)>{{ $format->label() }}</option>@endforeach</select></div>
            <div class="form-group field mb-3"><label class="form-label" for="{{ $formIdPrefix }}ScoringType">Правила подсчёта</label><select id="{{ $formIdPrefix }}ScoringType" class="form-select" name="scoring_type" data-game-scoring-type>@foreach(\App\Modules\Event\Domain\Enums\GameScoringTypeEnum::cases() as $scoringType)<option value="{{ $scoringType->value }}" @selected($selectedScoringType === $scoringType->value)>{{ $scoringType->label() }}</option>@endforeach</select></div>
            <div class="row g-3 mb-3"><div class="col-md-6 form-group field"><label class="form-label" for="{{ $formIdPrefix }}TimingMode">Режим времени</label><select id="{{ $formIdPrefix }}TimingMode" class="form-select" name="timing_mode" data-game-timing-mode>@foreach(\App\Modules\Event\Domain\Enums\GameTimingModeEnum::cases() as $mode)<option value="{{ $mode->value }}" @selected($selectedTimingMode === $mode->value)>{{ $mode->label() }}</option>@endforeach</select></div><div class="col-md-6 form-group field" data-game-periods-field @if($selectedTimingMode !== 'periods') hidden @endif><label class="form-label" for="{{ $formIdPrefix }}PeriodsCount">Количество периодов</label><select id="{{ $formIdPrefix }}PeriodsCount" class="form-select" name="periods_count" data-game-periods-count>@foreach(\App\Modules\Event\Domain\Enums\GamePeriodsCountEnum::cases() as $count)<option value="{{ $count->value }}" @selected((int) old('periods_count', 4) === $count->value)>{{ $count->label() }}</option>@endforeach</select></div></div>
            <div class="row g-3 mb-3">
                <div class="col-md-6 form-group field">
                    <label class="form-label" for="{{ $formIdPrefix }}TeamA">Команда A</label>
                    <select id="{{ $formIdPrefix }}TeamA" class="form-select @error('team_a_id') is-invalid @enderror" name="team_a_id">
                        <option value="">Выберите команду</option>
                        @foreach($teams as $team)
                            <option value="{{ $team->id }}" @selected((string) old('team_a_id') === (string) $team->id)>{{ $team->name }}</option>
                        @endforeach
                    </select>
                    @error('team_a_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-6 form-group field">
                    <label class="form-label" for="{{ $formIdPrefix }}TeamB">Команда B</label>
                    <select id="{{ $formIdPrefix }}TeamB" class="form-select @error('team_b_id') is-invalid @enderror" name="team_b_id">
                        <option value="">Выберите команду</option>
                        @foreach($teams as $team)
                            <option value="{{ $team->id }}" @selected((string) old('team_b_id') === (string) $team->id)>{{ $team->name }}</option>
                        @endforeach
                    </select>
                    @error('team_b_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-6 form-group field">
                    <label class="form-label" for="{{ $formIdPrefix }}SideASize">Игроков на площадке у A</label>
                    <select id="{{ $formIdPrefix }}SideASize" class="form-select" name="side_a_size" data-game-side-size>
                        @foreach(range(1, 7) as $size)<option value="{{ $size }}" @selected((int) old('side_a_size', $defaultSideSize) === $size)>{{ $size }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-6 form-group field">
                    <label class="form-label" for="{{ $formIdPrefix }}SideBSize">Игроков на площадке у B</label>
                    <select id="{{ $formIdPrefix }}SideBSize" class="form-select" name="side_b_size" data-game-side-size>
                        @foreach(range(1, 7) as $size)<option value="{{ $size }}" @selected((int) old('side_b_size', $defaultSideSize) === $size)>{{ $size }}</option>@endforeach
                    </select>
                </div>
            </div>
        </fieldset>
    @endif
    <div class="form-group field mb-3">
        @include('theme::partials.venues.predictive-selector', [
            'id' => $formIdPrefix.'Venue',
            'selectedVenue' => $selectedVenue,
            'confirmedOnly' => true,
            'operationalStatus' => 'active',
            'startInput' => '#'.$formIdPrefix.'StartsAt',
            'durationInput' => '#'.$formIdPrefix.'Duration',
            'mapModal' => $formIdPrefix.'-venue-map',
            'showFavorites' => true,
        ])
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-6 form-group field">
            <label class="form-label" for="{{ $formIdPrefix }}StartsAt">Начало</label>
        <input id="{{ $formIdPrefix }}StartsAt" type="datetime-local" class="form-control @error('starts_at') is-invalid @enderror" name="starts_at" value="{{ old('starts_at', $coordinatedStartsAt ?? $defaultStartsAt) }}" min="{{ $minimumStartsAt ?? $defaultStartsAt }}" required data-event-start>
            @error('starts_at') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-6 form-group field">
            <label class="form-label" for="{{ $formIdPrefix }}Duration">Длительность</label>
            <select id="{{ $formIdPrefix }}Duration" class="form-select @error('duration_minutes') is-invalid @enderror" name="duration_minutes" required>
                @foreach($displayDurationOptions as $minutes)
                    @php
                        $hours = $minutes / 60;
                        $durationLabel = $minutes === 30
                            ? '30 минут'
                            : number_format($hours, $minutes % 60 === 0 ? 0 : 1, ',', '').' '.($hours === 1.0 ? 'час' : ($minutes % 60 !== 0 || $hours < 5 ? 'часа' : 'часов'));
                    @endphp
                    <option value="{{ $minutes }}" @selected($selectedDuration === $minutes)>{{ $durationLabel }}</option>
                @endforeach
            </select>
            @error('duration_minutes') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-6 form-group field">
            <label class="form-label" for="{{ $formIdPrefix }}Capacity">Количество участников</label>
            <input id="{{ $formIdPrefix }}Capacity" type="number" min="2" max="500" class="form-control @error('max_participants') is-invalid @enderror" name="max_participants" value="{{ old('max_participants') }}" placeholder="Без ограничения">
            @error('max_participants') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-6 form-group field">
            <label class="form-label" for="{{ $formIdPrefix }}Visibility">Доступ</label>
            <select id="{{ $formIdPrefix }}Visibility" class="form-select" name="visibility">
                @foreach($visibilities as $visibility)<option value="{{ $visibility->value }}" @selected(old('visibility', 'public') === $visibility->value)>{{ $visibility->label() }}</option>@endforeach
            </select>
        </div>
    </div>
    <div class="form-group field mb-4">
        <label class="form-label" for="{{ $formIdPrefix }}Description">Описание</label>
        <textarea id="{{ $formIdPrefix }}Description" class="form-control @error('description') is-invalid @enderror" name="description" rows="5" maxlength="5000">{{ old('description', $defaultDescription ?? null) }}</textarea>
        @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    @if(isset($coordinationParticipants) && $coordinationParticipants->isNotEmpty())
        <fieldset class="form-group field mb-4">
            <legend class="form-label">Участники по результатам опроса</legend>
            <p class="form-text mb-2">Отметьте пользователей, которых нужно сразу добавить в мероприятие. Положительные ответы выбраны автоматически.</p>
            <div class="coordination-participant-selection">
                @foreach($coordinationParticipants as $participant)
                    <label class="coordination-checkbox">
                        <input
                            class="coordination-checkbox__input"
                            type="checkbox"
                            name="participant_user_ids[]"
                            value="{{ $participant['id'] }}"
                            @checked(in_array((string) $participant['id'], array_map('strval', old('participant_user_ids', $coordinationParticipants->where('intent', 'going')->pluck('id')->all())), true))
                        >
                        <span class="coordination-checkbox__control" aria-hidden="true"></span>
                        <span class="coordination-checkbox__label">
                            <strong>{{ $participant['name'] }}</strong>
                            <small>{{ $participant['answer'] }}</small>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('participant_user_ids') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            @error('participant_user_ids.*') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
        </fieldset>
    @endif
    @if(isset($telegramChats) && $telegramChats->isNotEmpty())
        @php
            $defaultTelegramChatIds = $telegramChats->pluck('id')->map(fn ($id) => (string) $id)->all();
            $selectedTelegramChatIds = array_map('strval', old('telegram_chat_ids', $defaultTelegramChatIds));
        @endphp
        <fieldset class="form-group field mb-4">
            @include('theme::partials.forms.toggle', [
                'name' => 'publish_to_telegram',
                'id' => $formIdPrefix.'PublishToTelegram',
                'title' => 'Опубликовать в Telegram',
                'description' => 'Мероприятие появится в выбранных чатах, изменения будут синхронизироваться с порталом',
                'checked' => old('publish_to_telegram', true),
                'wrapperClass' => '',
            ])
            <div class="coordination-participant-selection mt-3">
                @foreach($telegramChats as $telegramChat)
                    <label class="coordination-checkbox">
                        <input
                            class="coordination-checkbox__input"
                            type="checkbox"
                            name="telegram_chat_ids[]"
                            value="{{ $telegramChat->id }}"
                            @checked(in_array((string) $telegramChat->id, $selectedTelegramChatIds, true))
                        >
                        <span class="coordination-checkbox__control" aria-hidden="true"></span>
                        <span class="coordination-checkbox__label">
                            <strong>{{ $telegramChat->title ?: 'Чат '.$telegramChat->telegram_chat_id }}</strong>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('telegram_chat_ids') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            @error('telegram_chat_ids.*') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
        </fieldset>
    @else
        <input type="hidden" name="publish_to_telegram" value="0">
    @endif
    <button
        class="btn btn--primary"
        type="submit"
        @if(!empty($confirmMessage)) onclick='return confirm(@json($confirmMessage))' @endif
    >{{ $submitLabel }}</button>
</form>

@component('theme::partials.modal.layout', ['id' => 'event-favorite-venues'])
    <h2 class="modal_title" id="modal-title-event-favorite-venues">Избранные площадки</h2>
    <p class="modal-description">Функционал находится в разработке.</p>
@endcomponent
