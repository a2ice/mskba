@php
    $initialType = old('type', $selectedType->value);
    $initialFormat = old('game_format', \App\Modules\Event\Domain\Enums\GameFormatEnum::STREETBALL_3X3->value);
    $initialRecruitment = old('game_recruitment_mode', \App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum::PREFORMED_TEAMS->value);
    $initialScoring = old('scoring_type', \App\Modules\Event\Domain\Enums\GameScoringTypeEnum::STREETBALL->value);
    $initialTiming = old('timing_mode', $initialFormat === \App\Modules\Event\Domain\Enums\GameFormatEnum::BASKETBALL_5X5->value ? 'periods' : 'whole_game');
    $initialSideA = (int) old('side_a_size', \App\Modules\Event\Domain\Enums\GameFormatEnum::tryFrom($initialFormat)?->sideSize() ?? 3);
    $initialSideB = (int) old('side_b_size', \App\Modules\Event\Domain\Enums\GameFormatEnum::tryFrom($initialFormat)?->sideSize() ?? 3);
    $initialTelegramChatIds = array_map('strval', old('telegram_chat_ids', $telegramChats->pluck('id')->all()));
@endphp

@extends('theme::layouts.app', ['title' => 'Создать мероприятие'])

@section('content')
<section class="first-screen event-wizard-page">
    <div class="inner event-wizard-page__inner">
        <header class="event-wizard-hero">
            <span class="eyebrow">Новый flow · beta</span>
            <h1>Создать мероприятие</h1>
            <p>Несколько понятных решений вместо одной длинной формы. Текущий способ создания пока остаётся доступным.</p>
        </header>

        @if(session('error'))
            <div class="alert alert-danger mb-3">{{ session('error') }}</div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger mb-3">Проверьте отмеченные поля. Ваши ответы сохранены.</div>
        @endif

        <form
            method="POST"
            action="{{ route('events.store') }}"
            class="event-wizard"
            data-event-wizard
            data-team-search-url="{{ route('events.wizard.teams') }}"
            data-default-title="{{ $defaultTitle }}"
        >
            @csrf

            <div class="event-wizard__progress" aria-label="Прогресс создания">
                <div class="event-wizard__progress-copy">
                    <strong data-wizard-progress-title>Шаг 1</strong>
                    <span data-wizard-progress-count>1 из 7</span>
                </div>
                <div class="event-wizard__progress-track" aria-hidden="true">
                    <span data-wizard-progress-bar></span>
                </div>
            </div>

            <div class="event-wizard__layout">
                <main class="event-wizard__main">
                    <section class="event-wizard-step" data-wizard-step="type" data-step-title="Что создаём?">
                        <div class="event-wizard-step__heading">
                            <span class="event-wizard-step__number">01</span>
                            <div>
                                <h2>Что создаём?</h2>
                                <p>Тип определяет следующие шаги. Предустановленный вариант всегда можно сменить.</p>
                            </div>
                        </div>

                        <div class="event-wizard-choice-grid event-wizard-choice-grid--types">
                            @foreach($types as $type)
                                @php
                                    $typeDescription = match($type) {
                                        \App\Modules\Event\Domain\Enums\EventTypeEnum::GAME => 'Две стороны, счёт, статистика и live.',
                                        \App\Modules\Event\Domain\Enums\EventTypeEnum::GAME_TRAINING => 'Участники и несколько внутренних мини-игр.',
                                        \App\Modules\Event\Domain\Enums\EventTypeEnum::TRAINING => 'Встреча на площадке без обязательной игры.',
                                    };
                                @endphp
                                <label class="event-wizard-choice">
                                    <input
                                        type="radio"
                                        name="type"
                                        value="{{ $type->value }}"
                                        required
                                        data-wizard-type
                                        @checked($initialType === $type->value)
                                    >
                                    <span class="event-wizard-choice__surface">
                                        <i class="ti {{ $type === \App\Modules\Event\Domain\Enums\EventTypeEnum::GAME ? 'ti-ball-basketball' : ($type === \App\Modules\Event\Domain\Enums\EventTypeEnum::GAME_TRAINING ? 'ti-layout-grid' : 'ti-run') }}" aria-hidden="true"></i>
                                        <strong>{{ $type->label() }}</strong>
                                        <small>{{ $typeDescription }}</small>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </section>

                    <section class="event-wizard-step" data-wizard-step="game" data-step-title="Как играем?" hidden>
                        <div class="event-wizard-step__heading">
                            <span class="event-wizard-step__number">02</span>
                            <div>
                                <h2>Как играем?</h2>
                                <p>Выберите понятный preset. Технические правила подстроятся автоматически.</p>
                            </div>
                        </div>

                        <fieldset class="event-wizard-fieldset" data-game-only>
                            <legend>Формат</legend>
                            <div class="event-wizard-choice-grid event-wizard-choice-grid--formats">
                                @foreach(\App\Modules\Event\Domain\Enums\GameFormatEnum::cases() as $format)
                                    <label class="event-wizard-choice event-wizard-choice--compact">
                                        <input type="radio" name="game_format" value="{{ $format->value }}" data-game-format @checked($initialFormat === $format->value)>
                                        <span class="event-wizard-choice__surface">
                                            <strong>{{ $format->label() }}</strong>
                                            <small>
                                                @if($format === \App\Modules\Event\Domain\Enums\GameFormatEnum::BASKETBALL_5X5) 5 на 5 · баскетбольный счёт
                                                @elseif($format === \App\Modules\Event\Domain\Enums\GameFormatEnum::STREETBALL_3X3) 3 на 3 · стритбольный счёт
                                                @elseif($format === \App\Modules\Event\Domain\Enums\GameFormatEnum::STREETBALL_1X1) 1 на 1 · стритбольный счёт
                                                @else Размеры и правила задаёте сами
                                                @endif
                                            </small>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>

                        <div class="event-wizard-dynamic-card" data-custom-format-fields hidden>
                            <div class="event-wizard-form-grid">
                                <label class="field">
                                    <span class="form-label">Игроков у стороны A</span>
                                    <select class="form-select" data-custom-side-a>
                                        @foreach(range(1, 7) as $size)<option value="{{ $size }}" @selected($initialSideA === $size)>{{ $size }}</option>@endforeach
                                    </select>
                                </label>
                                <label class="field">
                                    <span class="form-label">Игроков у стороны B</span>
                                    <select class="form-select" data-custom-side-b>
                                        @foreach(range(1, 7) as $size)<option value="{{ $size }}" @selected($initialSideB === $size)>{{ $size }}</option>@endforeach
                                    </select>
                                </label>
                                <label class="field">
                                    <span class="form-label">Правила подсчёта</span>
                                    <select class="form-select" data-custom-scoring>
                                        @foreach(\App\Modules\Event\Domain\Enums\GameScoringTypeEnum::cases() as $scoring)
                                            <option value="{{ $scoring->value }}" @selected($initialScoring === $scoring->value)>{{ $scoring->label() }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="field">
                                    <span class="form-label">Режим времени</span>
                                    <select class="form-select" data-custom-timing>
                                        @foreach(\App\Modules\Event\Domain\Enums\GameTimingModeEnum::cases() as $timing)
                                            <option value="{{ $timing->value }}" @selected($initialTiming === $timing->value)>{{ $timing->label() }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            </div>
                        </div>

                        <input type="hidden" name="side_a_size" value="{{ $initialSideA }}" data-game-side-a data-game-payload>
                        <input type="hidden" name="side_b_size" value="{{ $initialSideB }}" data-game-side-b data-game-payload>
                        <input type="hidden" name="scoring_type" value="{{ $initialScoring }}" data-game-scoring data-game-payload>
                        <input type="hidden" name="timing_mode" value="{{ $initialTiming }}" data-game-timing data-game-payload>

                        <div class="event-wizard-dynamic-card" data-periods-card @if($initialTiming !== 'periods') hidden @endif>
                            <label class="field">
                                <span class="form-label">Количество периодов</span>
                                <div class="event-wizard-segmented">
                                    @foreach([2, 4] as $periods)
                                        <label><input type="radio" name="periods_count" value="{{ $periods }}" @checked((int) old('periods_count', 4) === $periods)><span>{{ $periods }} периода</span></label>
                                    @endforeach
                                </div>
                            </label>
                        </div>

                        <fieldset class="event-wizard-fieldset" data-game-only>
                            <legend>Как собираются стороны?</legend>
                            <div class="event-wizard-choice-grid event-wizard-choice-grid--recruitment">
                                <label class="event-wizard-choice event-wizard-choice--compact">
                                    <input type="radio" name="game_recruitment_mode" value="preformed_teams" data-recruitment-mode @checked($initialRecruitment === 'preformed_teams')>
                                    <span class="event-wizard-choice__surface"><strong>Готовые команды</strong><small>Можно выбрать 0, 1 или 2 команды сейчас.</small></span>
                                </label>
                                <label class="event-wizard-choice event-wizard-choice--compact">
                                    <input type="radio" name="game_recruitment_mode" value="individual_draft" data-recruitment-mode @checked($initialRecruitment === 'individual_draft')>
                                    <span class="event-wizard-choice__surface"><strong>Отдельные игроки</strong><small>Принятых игроков позже распределим balanced-алгоритмом.</small></span>
                                </label>
                            </div>
                        </fieldset>

                        <label class="event-wizard-toggle" data-game-only>
                            <input type="hidden" name="game_accepts_applications" value="0" data-game-payload>
                            <input type="checkbox" name="game_accepts_applications" value="1" data-game-payload @checked(old('game_accepts_applications', true))>
                            <span><strong>Принимать заявки</strong><small>Организатор всё равно сможет отправлять приглашения.</small></span>
                        </label>
                    </section>

                    <section class="event-wizard-step" data-wizard-step="schedule" data-step-title="Когда?" hidden>
                        <div class="event-wizard-step__heading">
                            <span class="event-wizard-step__number">03</span>
                            <div><h2>Когда?</h2><p>Время и длительность нужны, чтобы сразу показать только реально доступные площадки.</p></div>
                        </div>
                        <div class="event-wizard-form-grid event-wizard-form-grid--schedule">
                            <label class="field">
                                <span class="form-label">Дата и время начала</span>
                                <input id="wizardStartsAt" type="datetime-local" class="form-control @error('starts_at') is-invalid @enderror" name="starts_at" value="{{ old('starts_at', $defaultStartsAt) }}" min="{{ $minimumStartsAt }}" required data-wizard-start>
                                @error('starts_at')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                            </label>
                            <label class="field">
                                <span class="form-label">Длительность</span>
                                <select id="wizardDuration" class="form-select @error('duration_minutes') is-invalid @enderror" name="duration_minutes" required data-wizard-duration>
                                    @foreach($durationOptions as $minutes)
                                        @php($durationLabel = $minutes < 60 ? $minutes.' мин' : (intdiv($minutes, 60).($minutes % 60 ? ':30' : '').' ч'))
                                        <option value="{{ $minutes }}" @selected((int) old('duration_minutes', 60) === $minutes)>{{ $durationLabel }}</option>
                                    @endforeach
                                </select>
                                @error('duration_minutes')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                            </label>
                        </div>
                        <div class="event-wizard-inline-summary"><i class="ti ti-clock" aria-hidden="true"></i><span data-schedule-summary>Выберите время</span></div>
                    </section>

                    <section class="event-wizard-step" data-wizard-step="venue" data-step-title="Где?" hidden>
                        <div class="event-wizard-step__heading">
                            <span class="event-wizard-step__number">04</span>
                            <div><h2>Где?</h2><p>Поиск учитывает выбранные дату, время и длительность. Сервер повторно проверит бронь при создании.</p></div>
                        </div>
                        <div class="event-wizard-venue-card">
                            @include('theme::partials.venues.predictive-selector', [
                                'id' => 'wizardVenue',
                                'selectedVenue' => null,
                                'selectedScope' => old('booking_scope', 'whole'),
                                'confirmedOnly' => true,
                                'operationalStatus' => 'active',
                                'startInput' => '#wizardStartsAt',
                                'durationInput' => '#wizardDuration',
                                'mapModal' => 'wizard-venue-map',
                                'showFavorites' => true,
                            ])
                        </div>
                        <div class="event-wizard-note"><i class="ti ti-layout-2" aria-hidden="true"></i><span>Для площадок с двумя кольцами можно выбрать всю площадку или отдельную половину. Занятые варианты помечаются автоматически.</span></div>
                    </section>

                    <section class="event-wizard-step" data-wizard-step="participants" data-step-title="Кто участвует?" hidden>
                        <div class="event-wizard-step__heading">
                            <span class="event-wizard-step__number">05</span>
                            <div><h2>Кто участвует?</h2><p data-participants-copy>Этот шаг можно пропустить и собрать участников после создания.</p></div>
                        </div>

                        <div data-team-picker-wrap>
                            <input type="hidden" name="team_a_id" value="{{ old('team_a_id') }}" data-team-a-id data-game-payload>
                            <input type="hidden" name="team_b_id" value="{{ old('team_b_id') }}" data-team-b-id data-game-payload>

                            <div class="event-wizard-team-slots" data-team-slots>
                                @foreach(['A', 'B'] as $side)
                                    <button type="button" class="event-wizard-team-slot {{ $side === 'A' ? 'is-active' : '' }}" data-team-slot="{{ $side }}">
                                        <span class="event-wizard-team-slot__logo"><i class="ti ti-plus"></i></span>
                                        <span><small>Сторона {{ $side }}</small><strong data-team-slot-name>Выбрать команду</strong><em data-team-slot-hint>Можно сделать позже</em></span>
                                    </button>
                                @endforeach
                            </div>

                            <div class="event-wizard-team-browser">
                                <div class="event-wizard-team-search">
                                    <i class="ti ti-search" aria-hidden="true"></i>
                                    <input class="form-control" type="search" placeholder="Начните вводить название команды…" autocomplete="off" data-team-search>
                                    <span class="event-wizard-team-search__status" data-team-search-status></span>
                                </div>
                                <div class="event-wizard-team-grid" data-team-grid aria-live="polite"></div>
                                <p class="event-wizard-empty" data-team-empty hidden>Команды не найдены.</p>
                            </div>

                            <p class="event-wizard-note"><i class="ti ti-info-circle"></i><span>Для вашей команды выбор фиксируется сразу. Чужой доступной команде после создания отправится приглашение. Для старта игры обе стороны должны согласиться и быть утверждены.</span></p>
                        </div>

                        <div class="event-wizard-dynamic-card" data-individual-recruitment hidden>
                            <div class="event-wizard-big-icon"><i class="ti ti-users-group"></i></div>
                            <div><strong>Игроков можно набрать после создания</strong><p>Опубликуйте игру, принимайте заявки или отправляйте приглашения. Когда пул будет готов, сформируйте сбалансированные стороны.</p></div>
                        </div>

                        <div class="event-wizard-dynamic-card" data-training-participants hidden>
                            <div class="event-wizard-big-icon"><i class="ti ti-user-plus"></i></div>
                            <div><strong>Участников можно добавить позже</strong><p>После создания мероприятия организатор сможет приглашать и подтверждать участников из страницы управления.</p></div>
                        </div>

                        <label class="field event-wizard-capacity">
                            <span class="form-label">Максимум участников <small>необязательно</small></span>
                            <input class="form-control @error('max_participants') is-invalid @enderror" type="number" name="max_participants" min="2" max="500" value="{{ old('max_participants') }}" placeholder="Без ограничения">
                            @error('max_participants')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                        </label>
                    </section>

                    <section class="event-wizard-step" data-wizard-step="details" data-step-title="О мероприятии" hidden>
                        <div class="event-wizard-step__heading">
                            <span class="event-wizard-step__number">06</span>
                            <div><h2>О мероприятии</h2><p>Название уже придумано автоматически. При желании поправьте его и добавьте детали.</p></div>
                        </div>
                        <label class="field mb-3">
                            <span class="form-label">Название</span>
                            <input class="form-control @error('title') is-invalid @enderror" name="title" maxlength="150" value="{{ old('title', $defaultTitle) }}" required data-wizard-title data-generated-title="1">
                            @error('title')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                        </label>
                        <label class="field mb-3">
                            <span class="form-label">Описание <small>необязательно</small></span>
                            <textarea class="form-control @error('description') is-invalid @enderror" name="description" rows="5" maxlength="5000" placeholder="Что важно знать участникам?">{{ old('description') }}</textarea>
                            @error('description')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                        </label>
                        <label class="field">
                            <span class="form-label">Кто увидит мероприятие</span>
                            <select class="form-select" name="visibility" data-wizard-visibility>
                                @foreach($visibilities as $visibility)
                                    <option value="{{ $visibility->value }}" @selected(old('visibility', 'public') === $visibility->value)>{{ $visibility->label() }}</option>
                                @endforeach
                            </select>
                        </label>
                    </section>

                    <section class="event-wizard-step" data-wizard-step="publication" data-step-title="Публикация" hidden>
                        <div class="event-wizard-step__heading">
                            <span class="event-wizard-step__number">07</span>
                            <div><h2>Публикация</h2><p>Дополнительный шаг. Его можно полностью пропустить.</p></div>
                        </div>
                        <input type="hidden" name="publish_to_telegram" value="0">
                        @if($telegramChats->isNotEmpty())
                            <label class="event-wizard-toggle event-wizard-toggle--large">
                                <input type="checkbox" name="publish_to_telegram" value="1" data-publish-telegram @checked(old('publish_to_telegram', false))>
                                <span><strong>Опубликовать в Telegram</strong><small>Изменения мероприятия дальше будут синхронизироваться с выбранными чатами.</small></span>
                            </label>
                            <div class="event-wizard-chat-list" data-telegram-chats hidden>
                                @foreach($telegramChats as $chat)
                                    <label class="event-wizard-chat">
                                        <input type="checkbox" name="telegram_chat_ids[]" value="{{ $chat->id }}" @checked(in_array((string) $chat->id, $initialTelegramChatIds, true))>
                                        <span><i class="ti ti-brand-telegram"></i><strong>{{ $chat->title ?: 'Чат '.$chat->telegram_chat_id }}</strong></span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="text-danger mt-2" data-telegram-error hidden>Выберите хотя бы один чат.</p>
                        @else
                            <div class="event-wizard-dynamic-card"><div class="event-wizard-big-icon"><i class="ti ti-brand-telegram"></i></div><div><strong>Публикация в Telegram пока недоступна</strong><p>Нет активных чатов, принимающих публикации мероприятий.</p></div></div>
                        @endif
                    </section>

                    <section class="event-wizard-step" data-wizard-step="review" data-step-title="Проверьте" hidden>
                        <div class="event-wizard-step__heading">
                            <span class="event-wizard-step__number">✓</span>
                            <div><h2>Проверьте перед созданием</h2><p>Обязательные параметры должны быть заполнены. Команды и дополнительные детали можно определить позже.</p></div>
                        </div>
                        <div class="event-wizard-review">
                            <button type="button" class="event-wizard-review__row" data-review-edit="type"><span><small>Тип</small><strong data-review-type>—</strong></span><i class="ti ti-pencil"></i></button>
                            <button type="button" class="event-wizard-review__row" data-review-edit="game" data-review-game-row><span><small>Формат</small><strong data-review-game>—</strong></span><i class="ti ti-pencil"></i></button>
                            <button type="button" class="event-wizard-review__row" data-review-edit="schedule"><span><small>Когда</small><strong data-review-schedule>—</strong></span><i class="ti ti-pencil"></i></button>
                            <button type="button" class="event-wizard-review__row" data-review-edit="venue"><span><small>Площадка</small><strong data-review-venue>—</strong></span><i class="ti ti-pencil"></i></button>
                            <button type="button" class="event-wizard-review__row" data-review-edit="participants"><span><small>Участники</small><strong data-review-participants>Определим позже</strong></span><i class="ti ti-pencil"></i></button>
                            <button type="button" class="event-wizard-review__row" data-review-edit="details"><span><small>Название</small><strong data-review-title>—</strong></span><i class="ti ti-pencil"></i></button>
                        </div>
                        <div class="event-wizard-review__warning" data-review-warning hidden><i class="ti ti-alert-triangle"></i><span></span></div>
                        <button class="btn btn--primary event-wizard-submit" type="submit" data-wizard-submit>Создать мероприятие</button>
                    </section>

                    <div class="event-wizard__actions" data-wizard-actions>
                        <button class="btn btn--secondary" type="button" data-wizard-back>Назад</button>
                        <button class="event-wizard-skip" type="button" data-wizard-skip hidden>Пропустить</button>
                        <button class="btn btn--primary" type="button" data-wizard-next>Далее</button>
                    </div>
                </main>

                <aside class="event-wizard-summary" aria-label="Ваше мероприятие">
                    <span class="eyebrow">Ваше мероприятие</span>
                    <strong class="event-wizard-summary__title" data-summary-title>{{ old('title', $defaultTitle) }}</strong>
                    <dl>
                        <div><dt>Тип</dt><dd data-summary-type>—</dd></div>
                        <div data-summary-game-row><dt>Формат</dt><dd data-summary-game>—</dd></div>
                        <div><dt>Когда</dt><dd data-summary-schedule>—</dd></div>
                        <div><dt>Где</dt><dd data-summary-venue>Не выбрано</dd></div>
                        <div><dt>Участники</dt><dd data-summary-participants>Позже</dd></div>
                    </dl>
                    <p class="event-wizard-summary__hint">Можно возвращаться назад: уже введённые значения сохраняются в форме.</p>
                    <a href="{{ route('events.create', ['type' => $selectedType->value]) }}" class="fc-link">Открыть старую форму</a>
                </aside>
            </div>
        </form>
    </div>
</section>
@endsection
