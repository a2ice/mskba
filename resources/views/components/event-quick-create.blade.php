@php
    $quickApp = app(\App\Presentation\Theming\ThemeResolver::class)->active() === 'mskba_app';
    $quickPrimary = $quickApp ? 'button primary' : 'btn btn--primary';
    $quickSecondary = $quickApp ? 'button secondary' : 'btn btn--secondary';
    $quickVenues = \App\Modules\Venue\Domain\Models\Venue::query()
        ->where('status', 'confirmed')->where('is_published', true)->with(['courts', 'location.address', 'location.metroStations'])
        ->orderBy('name')->get();
    // Fetch active venue policies in one query; court overrides are already loaded.
    $quickBookingPolicies = \App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy::query()
        ->whereIn('venue_id', $quickVenues->modelKeys())->where('active_marker', true)
        ->pluck('minimum_lead_time_minutes', 'venue_id');
    $quickBookingLead = $quickVenues->mapWithKeys(fn ($place) => [(string) $place->id =>
        (int) ($quickBookingPolicies->get($place->id) ?? 0)])->all();
    $quickSelectedVenue = (int) ($quickEventVenueId ?? 0);
    $quickSelectedCourt = (int) ($quickEventCourtId ?? 0);
    $quickCourtChoices = $quickVenues->mapWithKeys(fn ($place) => [(string) $place->id =>
        $place->courts->map(fn ($court) => ['id' => $court->id, 'name' => $court->name, 'minimum_lead_time_minutes' => $court->minimum_lead_time_minutes])->values()->all()])->all();
    $quickVenueMeta = $quickVenues->mapWithKeys(fn ($place) => [(string) $place->id => [
        'id' => (int) $place->id,
        'name' => $place->name,
        'address' => $place->location?->address?->full_address ?: $place->raw_address,
        'street' => $place->location?->address?->street,
        'metro_stations' => ($place->location?->metroStations ?? collect())
            ->sortBy(fn ($station) => $station->pivot?->distance_meters ?? PHP_INT_MAX)
            ->pluck('name')->values()->all(),
        'latitude' => $place->location?->address?->latitude,
        'longitude' => $place->location?->address?->longitude,
    ]])->all();
    $quickInitialVenue = $quickVenues->firstWhere('id', $quickSelectedVenue);
@endphp
<dialog class="mskba-modal quick-event-dialog {{ $quickApp ? 'quick-event-dialog--app' : 'quick-event-dialog--dark' }}"
    data-quick-event-dialog data-authenticated="{{ auth()->check() ? '1' : '0' }}"
    data-initial-court-id="{{ $quickSelectedCourt }}"
    data-schedule-day-template="{{ route('venues.schedule.day', '__VENUE__') }}"
    data-courts='@json($quickCourtChoices)'
    data-booking-lead='@json($quickBookingLead)'
    data-venue-meta='@json($quickVenueMeta, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG)'
    aria-labelledby="quick-event-title">
    <header class="mskba-modal__header quick-event-dialog__heading">
        <div><h2 id="quick-event-title">Создать мероприятие</h2></div>
        <button type="button" class="icon-button quick-event-dialog__close" data-quick-event-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg></button>
    </header>
    <form method="POST" action="{{ route('events.funnel.store') }}" data-quick-event-form>
        @csrf
        <input type="hidden" name="event_request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
        <input type="hidden" name="game_recruitment_mode" value="individual_draft">
        <input type="hidden" name="game_accepts_applications" value="1">
        <input type="hidden" name="side_a_size" value="3" data-quick-side-a>
        <input type="hidden" name="side_b_size" value="3" data-quick-side-b>
        <input type="hidden" name="scoring_type" value="streetball" data-quick-scoring>
        <input type="hidden" name="timing_mode" value="whole_game" data-quick-timing>
        <input type="hidden" name="periods_count" value="4">
        <section class="mskba-modal__body quick-event-dialog__body mskba-scroll" data-quick-step="base">
            <h3>1. О мероприятии</h3>
            <p class="quick-event-dialog__hint">Сначала общие сведения, затем — пожелания по времени. Бронь не создаётся.</p>
            <label class="quick-event-dialog__field"><span>Тип мероприятия</span>
                <span class="select-control"><select name="type" required data-quick-type>
                    <option value="" disabled selected>Выбери тип мероприятия</option>
                    @foreach(\App\Modules\Event\Domain\Enums\EventTypeEnum::creatable() as $kind)
                        <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                    @endforeach
                </select><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#chevron-down"/></svg></span>
            </label>
            <label class="quick-event-dialog__field"><span>Название <small>можно изменить</small></span>
                <input type="text" name="title" maxlength="150" minlength="2" placeholder="Например, играем 3×3" data-quick-title>
            </label>
            <div class="quick-event-dialog__field" data-quick-venue-field>
                <label for="quick-event-venue-search">Площадка</label>
                <div class="quick-event-dialog__venue-selector predictive-search__input-wrap" data-quick-venue-selector data-search-url="{{ route('venues.search') }}" data-confirmed-only="1">
                    <input id="quick-event-venue-search" class="predictive-search__input" type="search" required autocomplete="off"
                        placeholder="Название, адрес или метро" role="combobox" aria-autocomplete="list"
                        aria-expanded="false" aria-controls="quick-event-venue-results"
                        value="{{ $quickInitialVenue?->name ?? '' }}" data-quick-venue-search>
                    <input type="hidden" name="venue_id" value="{{ $quickSelectedVenue ?: '' }}" data-quick-venue>
                    <button type="button" class="quick-event-dialog__venue-clear predictive-search__control" data-quick-venue-clear
                        aria-label="Очистить площадку" @if(!$quickInitialVenue) hidden @endif></button>
                    <div id="quick-event-venue-results" class="quick-event-dialog__venue-results predictive-search__list" role="listbox"
                        data-quick-venue-results hidden></div>
                </div>
                <p class="quick-event-dialog__hint" data-quick-venue-message role="status" hidden></p>
                <button type="button" class="quick-event-dialog__venue-map-link" data-quick-venue-map-open>На карте</button>
            </div>
            <input type="hidden" name="create_game" value="0">
            <label class="quick-event-dialog__game-toggle" data-quick-create-game-row hidden>
                <input type="checkbox" name="create_game" value="1" data-quick-create-game checked>
                <span>Создать игру внутри мероприятия</span>
            </label>
            <p class="quick-event-dialog__hint" data-quick-create-game-hint hidden>Игру можно будет настроить отдельно. Если снять галочку, создастся только мероприятие; игру можно добавить позднее.</p>
            <label class="quick-event-dialog__field" data-quick-game-fields><span>Начальный формат игры</span>
                <span class="select-control"><select name="game_format" data-quick-format>
                    <option value="streetball_3x3">3×3</option>
                    <option value="basketball_5x5">5×5</option>
                    <option value="streetball_1x1">1×1</option>
                </select><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#chevron-down"/></svg></span>
            </label>
            <label class="quick-event-dialog__field"><span>Видимость</span>
                <span class="select-control"><select name="visibility" required>
                    <option value="public">Открытое для всех</option>
                    <option value="private">По приглашению</option>
                </select><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#chevron-down"/></svg></span>
            </label>
            <label class="quick-event-dialog__field"><span>Максимум участников</span>
                <input type="number" name="max_participants" min="2" max="500" placeholder="Без ограничения"></label>
            @include('components.event-participation-pricing-fields')
            <label class="quick-event-dialog__field"><span>Описание</span>
                <textarea name="description" rows="3" maxlength="5000"
                    placeholder="Кого ждёшь и как будет проходить мероприятие?"></textarea></label>
            @include('components.event-feedback-fields')
            @include('components.event-social-publication-fields')
        </section>
        <section class="mskba-modal__body quick-event-dialog__body mskba-scroll" data-quick-step="schedule" hidden>
            <h3>2. Желаемая дата и время <small>необязательно</small></h3>
            <p class="quick-event-dialog__hint">Можно не указывать дату. Выбранные интервалы — предварительные пожелания, не бронь. Даже если слот уже занят, можно отправить менеджеру запрос на согласование.</p>
            <label class="quick-event-dialog__field"><span>Зал или корт</span>
                <span class="select-control"><select name="venue_court_id" data-quick-court>
                    <option value="">Любой зал</option>
                </select><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#chevron-down"/></svg></span></label>
            <label class="quick-event-dialog__game-toggle">
                <input type="checkbox" data-quick-schedule-enabled aria-controls="quick-event-schedule-fields" aria-expanded="false">
                <span>Указать желаемую дату</span>
            </label>
            <p class="quick-event-dialog__hint" data-quick-schedule-off>Без даты мероприятие будет создано как черновик. Дату и время можно указать позднее.</p>
            <fieldset id="quick-event-schedule-fields" class="quick-event-dialog__schedule-fields" data-quick-schedule-fields disabled hidden>
            <div class="quick-event-dialog__grid">
                <label class="quick-event-dialog__field"><span>Дата</span>
                    <input type="date" data-quick-date min="{{ now(config('app.timezone','Europe/Moscow'))->format('Y-m-d') }}"></label>
                <label class="quick-event-dialog__field"><span>Время начала</span>
                    <input type="time" data-quick-time step="60"></label>
            </div>
            <div class="quick-event-dialog__grid">
                <label class="quick-event-dialog__field"><span>Продолжительность</span>
                    <span class="select-control"><select data-quick-duration>
                        @foreach(range(30,480,30) as $duration)
                            <option value="{{ $duration }}" @selected($duration === 90)>{{ $duration }} мин.</option>
                        @endforeach
                    </select><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#chevron-down"/></svg></span></label>
                <div class="quick-event-dialog__schedule-buttons">
                    <button type="button" class="{{ $quickPrimary }} quick-event-dialog__add-slot" data-quick-add-slot title="Добавить указанные дату, время и продолжительность в список интервалов" aria-label="Добавить указанный интервал"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></button>
                </div>
            </div>
            <button type="button" class="quick-event-dialog__venue-map-link" data-quick-show-schedule>Посмотреть занятость</button>
            <div class="quick-event-dialog__availability" data-quick-availability hidden aria-live="polite"></div>
            <h4>Выбранные интервалы</h4>
            <ol class="quick-event-dialog__slots" data-quick-slots></ol>
            <p class="quick-event-dialog__hint" data-quick-no-slots>Дополнительные интервалы не добавлены. Указанное время будет добавлено при нажатии «Далее».</p>
            <fieldset class="quick-event-dialog__mode" data-quick-mode-group hidden>
                <legend>Что означают эти интервалы?</legend>
                <label><input type="radio" name="schedule_mode" value="alternatives" checked>
                    <span>Это разные варианты времени <strong>одного мероприятия</strong> — согласуем один</span></label>
                <label><input type="radio" name="schedule_mode" value="separate">
                    <span>Это <strong>несколько отдельных мероприятий</strong> — по одному на каждый интервал</span></label>
            </fieldset>
            </fieldset>
            @include('components.event-time-comment-fields')
        </section>
        <section class="mskba-modal__body quick-event-dialog__body mskba-scroll" data-quick-step="review" hidden>
            <h3>3. Подтверждение</h3>
            <div data-quick-review></div>
            <p class="quick-event-dialog__hint"><strong>Важно:</strong> создание мероприятия или предварительной заявки не является бронированием. Менеджер площадки согласует время и условия отдельно.</p>
            @include('components.event-organizer-attendance-toggle', ['organizerToggleClass' => 'quick-event-dialog__game-toggle'])
            @include('components.event-organizer-role-toggle', ['organizerToggleClass' => 'quick-event-dialog__game-toggle'])
            <p data-quick-feedback role="alert" hidden></p>
        </section>
        <footer class="mskba-modal__footer quick-event-dialog__actions">
            <button type="button" class="{{ $quickSecondary }}" data-quick-back hidden>Назад</button>
            <button type="button" class="{{ $quickPrimary }}" data-quick-next>Далее</button>
            <button type="submit" class="{{ $quickPrimary }}" data-quick-submit hidden>Создать мероприятие</button>
        </footer>
    </form>
</dialog>
<dialog class="mskba-modal quick-event-venue-map {{ $quickApp ? 'quick-event-venue-map--app' : 'quick-event-venue-map--dark' }}"
    data-quick-venue-map-dialog aria-labelledby="quick-event-venue-map-title">
    <header class="mskba-modal__header quick-event-dialog__heading">
        <h2 id="quick-event-venue-map-title">Выбрать площадку на карте</h2>
        <button type="button" class="icon-button quick-event-dialog__close" data-quick-venue-map-close aria-label="Закрыть">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg>
        </button>
    </header>
    <div class="mskba-modal__body quick-event-venue-map__body">
        <p class="quick-event-dialog__hint" data-quick-venue-map-status role="status">Нажмите на площадку на карте.</p>
        <div class="quick-event-venue-map__stage">
            <div class="quick-event-venue-map__canvas" data-quick-venue-map-canvas
                data-map-api-key="{{ config('integrations.yandex.api_key') }}" aria-label="Карта площадок"></div>
            <div class="quick-event-venue-map__preview" data-quick-venue-map-preview
                aria-label="Информация о площадке" hidden></div>
        </div>
        <div class="quick-event-venue-map__toolbar">
            <button type="button" class="{{ $quickSecondary }}" data-quick-venue-map-nearby
                aria-pressed="false" hidden>Рядом со мной</button>
        </div>
        <div class="quick-event-venue-map__fallback" data-quick-venue-map-fallback hidden></div>
    </div>
    <footer class="mskba-modal__footer quick-event-dialog__actions">
        <button type="button" class="{{ $quickSecondary }}" data-quick-venue-map-close>Закрыть</button>
    </footer>
</dialog>
<dialog class="mskba-modal quick-event-auth {{ $quickApp ? 'quick-event-auth--app' : 'quick-event-auth--dark' }}" data-quick-auth-info aria-labelledby="quick-event-auth-title">
    <header class="mskba-modal__header quick-event-dialog__heading"><h2 id="quick-event-auth-title">Нужна авторизация</h2>
        <button type="button" class="icon-button quick-event-dialog__close" data-quick-auth-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg></button></header>
    <div class="mskba-modal__body quick-event-auth__content mskba-scroll">
        <p>Чтобы создать мероприятие, необходимо войти или зарегистрироваться. Все введённые данные и выбранные интервалы сохранятся. После входа мы завершим создание и откроем мероприятие.</p>
    </div>
    <footer class="mskba-modal__footer quick-event-dialog__actions">
        <button type="button" class="{{ $quickSecondary }}" data-quick-auth-close>Позже</button>
        <button type="button" class="{{ $quickPrimary }}" data-quick-auth-login>Войти</button>
    </footer>
</dialog>
@once
<style>
/* Shared MSKBA Modal 1.1. Dark theme retains its native palette. */
.quick-event-dialog,.quick-event-auth{
    position:fixed;inset:0;margin:auto;transform:none;box-sizing:border-box;
    width:min(670px,calc(100vw - 24px));max-width:calc(100vw - 24px);
    max-height:90dvh;padding:0;overflow:hidden;color:var(--text,#f5f6f8);
    flex-direction:column;
}
.quick-event-auth{width:min(520px,calc(100vw - 24px))}
.quick-event-dialog:not([open]),.quick-event-auth:not([open]){display:none}
.quick-event-dialog[open],.quick-event-auth[open]{display:flex}
.quick-event-dialog--dark,.quick-event-auth--dark{
    border:1px solid var(--line,#59616c);border-radius:16px;
    background:var(--surface,#1d2027);box-shadow:0 22px 80px #0008;
}
.quick-event-dialog--dark::backdrop,.quick-event-auth--dark::backdrop{background:rgba(0,0,0,.65)}
.quick-event-dialog__heading{
    display:grid;grid-template-columns:16px minmax(0,1fr) 44px;
    align-items:center;gap:12px;padding:20px 22px;
    border-bottom:1px solid var(--line,#606772);
    flex:0 0 auto;cursor:grab;touch-action:none;user-select:none;
}
:is(.quick-event-dialog--dark,.quick-event-auth--dark)[open]>.quick-event-dialog__heading::before{
    content:"⠿";align-self:center;justify-self:center;
    font-size:1rem;line-height:1;color:var(--muted,#929aa4);pointer-events:none;
}
.quick-event-dialog__heading>div{min-width:0}
.quick-event-dialog__heading h2{font-size:clamp(20px,2vw,26px);line-height:1.3;margin:0;min-width:0;overflow-wrap:anywhere}
.quick-event-dialog__heading .quick-event-dialog__close{
    justify-self:end;width:44px;height:44px;padding:0;display:flex;
    align-items:center;justify-content:center;border:1px solid var(--line,#59616c);
    border-radius:12px;background:var(--surface,#1d2027);color:inherit;cursor:pointer;
}
.quick-event-dialog__close svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round}
.quick-event-dialog>form{
    width:100%;display:flex;flex:1 1 auto;flex-direction:column;
    min-height:0;gap:0;overflow:hidden;
}
.quick-event-dialog__body,.quick-event-auth__content{
    display:grid;gap:16px;padding:20px 22px;min-height:0;
    flex:1 1 auto;overflow-y:auto;overflow-x:hidden;align-content:start;
}
.quick-event-dialog__body[hidden],.quick-event-dialog__actions>[hidden],.quick-event-dialog__mode[hidden]{display:none}
.quick-event-dialog__body h3,.quick-event-dialog__body h4{margin:0}
.quick-event-dialog__hint{font-size:14px;line-height:20px;margin:0;color:var(--muted,#adb3bb)}
.quick-event-dialog__field{display:grid;gap:8px;min-width:0}
.quick-event-dialog__field>span:first-child{font-size:14px;line-height:20px;font-weight:600}
.quick-event-dialog__field :is(input,select,textarea){
    box-sizing:border-box;width:100%;min-width:0;min-height:48px;
    border:1px solid var(--control-line,#707a86);border-radius:12px;
    padding:11px 12px;background:var(--field,#292e35);color:var(--text,#f5f6f8);
    font-size:16px;line-height:24px;
}
.quick-event-dialog__field textarea{min-height:112px;resize:vertical}
.quick-event-dialog__field [aria-invalid=true]{border-color:var(--danger,#ff9398)}
.quick-event-dialog__error{color:var(--danger,#ff9398);font-size:14px;line-height:20px}
.quick-event-dialog__field .select-control{position:relative;display:block;min-width:0;width:100%}
.quick-event-dialog--app .select-control>select{appearance:none;-webkit-appearance:none;padding-right:48px;cursor:pointer}
.quick-event-dialog--app .select-control>svg{
    display:block;position:absolute;right:16px;top:50%;transform:translateY(-50%);
    width:20px;height:20px;stroke:currentColor;stroke-width:1.8;fill:none;
    color:var(--muted,#adb3bb);pointer-events:none;
}
.quick-event-dialog--dark .select-control>svg{display:none}
@media(forced-colors:active){
    .quick-event-dialog--app .select-control>select{appearance:auto;-webkit-appearance:auto}
    .quick-event-dialog--app .select-control>svg{display:none}
}
.quick-event-dialog__game-toggle[hidden],.quick-event-dialog__field[hidden],.quick-event-dialog__hint[hidden]{display:none}
.quick-event-dialog__game-toggle{display:flex;align-items:center;gap:12px;min-height:44px;cursor:pointer;font-weight:600}
.quick-event-dialog__game-toggle input{width:20px;height:20px;margin:0;flex:none;accent-color:var(--accent,#ed782c)}
.quick-event-dialog__schedule-fields{display:grid;gap:16px;min-width:0;margin:0;padding:0;border:0}
.quick-event-dialog__schedule-fields[hidden]{display:none}
.quick-event-dialog__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.quick-event-dialog__schedule-buttons{display:flex;align-items:end;gap:8px;flex-wrap:wrap}
.quick-event-dialog__add-slot{width:48px;height:48px;min-width:48px;padding:0;display:grid;place-items:center}
.quick-event-dialog__add-slot svg{width:22px;height:22px;stroke:currentColor;stroke-width:2;stroke-linecap:round;fill:none}
.quick-event-dialog__availability{display:flex;gap:8px;flex-wrap:wrap;padding:12px;border:1px solid var(--line,#606772);border-radius:12px}
.quick-event-dialog__availability[hidden]{display:none}
.quick-event-dialog__availability button{padding:8px;border:1px solid var(--line,#606772);border-radius:8px;color:inherit;background:var(--field,#333)}
.quick-event-dialog__availability button.is-busy{border-color:var(--accent,#d59b56);opacity:.85}
.quick-event-dialog__slots{margin:0;display:grid;gap:8px;padding-left:22px}
.quick-event-dialog__slots li{display:flex;align-items:center;gap:10px;justify-content:space-between;border-bottom:1px solid var(--line,#555);padding:7px}
.quick-event-dialog__mode{display:grid;gap:10px;border:1px solid var(--line,#666);border-radius:12px;padding:12px}
.quick-event-dialog__mode legend{font-weight:600}
.quick-event-dialog__mode label{display:flex;gap:9px;align-items:flex-start;cursor:pointer}
.quick-event-dialog__mode input{margin-top:4px}
.quick-event-dialog__actions{
    display:flex;gap:8px;justify-content:flex-end;
    padding:16px 22px;flex:0 0 auto;flex-wrap:wrap;border-top:1px solid var(--line,#606772);
}
@media(max-width:530px){
    .quick-event-dialog__heading,.quick-event-dialog__body,.quick-event-dialog__actions,.quick-event-auth__content{padding-inline:16px}
    .quick-event-dialog__grid{grid-template-columns:1fr}
    .quick-event-dialog__actions :is(.button,.btn){flex:1 1 auto}
}
@media(max-height:400px){
    .quick-event-dialog__heading,.quick-event-dialog__actions{padding-block:8px}
    .quick-event-dialog__body{padding-block:12px}
}

/* Task 337: one canonical predictive venue field and native MSKBA map dialog. */
.quick-event-dialog__field>label{font-size:14px;line-height:20px;font-weight:600}
.quick-event-dialog__venue-selector{position:relative;min-width:0}
.quick-event-dialog__venue-selector>input[type=search]{padding-right:48px;appearance:none;-webkit-appearance:none}
.quick-event-dialog__venue-selector>input[type=search]::-webkit-search-cancel-button{display:none}
.quick-event-dialog__venue-clear{
    position:absolute;right:10px;top:50%;transform:translateY(-50%);
    width:26px;height:26px;padding:0;border:0;border-radius:50%;
    background:transparent;color:var(--muted,#6f7780);cursor:pointer;
}
.quick-event-dialog__venue-clear::before,
.quick-event-dialog__venue-clear::after{
    position:absolute;top:12px;left:5px;width:16px;height:2px;
    border-radius:2px;background:currentColor;content:'';
}
.quick-event-dialog__venue-clear::before{transform:rotate(45deg)}
.quick-event-dialog__venue-clear::after{transform:rotate(-45deg)}
.quick-event-dialog__venue-clear[hidden]{display:none}
.quick-event-dialog__venue-clear:hover{background:var(--surface-raised,#e8ebed)}
.quick-event-dialog__venue-results{
    position:absolute;z-index:20;top:calc(100% + 6px);left:0;right:0;
    max-height:min(42dvh,300px);overflow:auto;
    background:var(--surface,#fff);border:1px solid var(--line,#b6bec8);
    border-radius:12px;box-shadow:0 12px 36px #0003;padding:6px;
}
.quick-event-dialog__venue-results[hidden]{display:none}
.quick-event-dialog__venue-results>button{
    width:100%;display:grid;gap:3px;padding:10px 12px;border:0;border-radius:8px;
    text-align:left;color:var(--text,#1d2730);background:transparent;cursor:pointer;
}
.quick-event-dialog__venue-results>button:is(:hover,:focus-visible){background:var(--surface-raised,#edf0f2)}
.quick-event-dialog__venue-results small{color:var(--muted,#707781);font-size:13px}
.quick-event-dialog__venue-map-link{
    justify-self:start;margin-top:0;padding:3px 0;min-height:44px;
    background:none;border:none;text-decoration:underline;text-underline-offset:3px;
    color:var(--accent,#b54e00);font:inherit;font-weight:600;cursor:pointer;
}
.quick-event-venue-map{
    box-sizing:border-box;position:fixed;inset:0;margin:auto;width:min(960px,calc(100vw - 24px));
    max-width:calc(100vw - 24px);max-height:90dvh;padding:0;overflow:hidden;
    color:var(--text,#1d2730);
}
.quick-event-venue-map:not([open]){display:none}
.quick-event-venue-map[open]{display:flex;flex-direction:column}
.quick-event-venue-map--dark{background:var(--surface,#1d2027);border:1px solid var(--line,#646a72);border-radius:16px}
.quick-event-venue-map__body{padding:16px;display:grid;gap:12px;flex:1 1 auto;min-height:0;overflow:auto}
.quick-event-venue-map__stage{position:relative;min-width:0}
.quick-event-venue-map__canvas{height:min(56dvh,540px);min-height:260px;width:100%;border:1px solid var(--line,#b3b3b3);border-radius:12px;overflow:hidden}
.quick-event-venue-map__toolbar{display:flex;justify-content:flex-start;align-items:center;min-height:0}
.quick-event-venue-map__toolbar>[hidden]{display:none}
.quick-event-venue-map__toolbar button{min-height:44px}
.quick-event-venue-map__preview{
    position:absolute;z-index:3;left:12px;right:12px;bottom:12px;
    max-height:calc(100% - 24px);overflow-y:auto;border-radius:14px;
    box-shadow:0 12px 36px #0004;
}
.quick-event-venue-map__preview[hidden]{display:none}
.quick-event-venue-map__preview>.mskba-venue-map-card{
    width:100%;
}
.quick-event-venue-map__fallback{display:grid;gap:6px}
.quick-event-venue-map__fallback[hidden]{display:none}
.quick-event-venue-map__fallback button{
    width:100%;min-height:44px;text-align:left;padding:8px 12px;
    border-radius:12px;border:1px solid var(--line);background:var(--surface);color:var(--text);
}
@media(max-width:540px){
    .quick-event-venue-map__canvas{height:48dvh;min-height:230px}
    .quick-event-venue-map__preview{left:8px;right:8px;bottom:8px}
}

</style>
@endonce
