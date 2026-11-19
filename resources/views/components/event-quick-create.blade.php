@php
    $quickApp = app(\App\Presentation\Theming\ThemeResolver::class)->active() === 'mskba_app';
    $quickPrimary = $quickApp ? 'button primary' : 'btn btn--primary';
    $quickSecondary = $quickApp ? 'button secondary' : 'btn btn--secondary';
    $quickVenues = \App\Modules\Venue\Domain\Models\Venue::query()
        ->where('status', 'confirmed')->where('is_published', true)->with('courts')
        ->orderBy('name')->get();
    $quickSelectedVenue = (int) ($quickEventVenueId ?? 0);
    $quickSelectedCourt = (int) ($quickEventCourtId ?? 0);
    $quickCourtChoices = $quickVenues->mapWithKeys(fn ($place) => [(string) $place->id =>
        $place->courts->map(fn ($court) => ['id' => $court->id, 'name' => $court->name])->values()->all()])->all();
@endphp
<dialog class="quick-event-dialog {{ $quickApp ? 'quick-event-dialog--app' : 'quick-event-dialog--dark' }}"
    data-quick-event-dialog data-authenticated="{{ auth()->check() ? '1' : '0' }}"
    data-initial-court-id="{{ $quickSelectedCourt }}"
    data-schedule-day-template="{{ route('venues.schedule.day', '__VENUE__') }}"
    data-courts='@json($quickCourtChoices)'
    aria-labelledby="quick-event-title">
    <header class="quick-event-dialog__heading">
        <div><p class="quick-event-dialog__eyebrow">MSKBA · новое мероприятие</p>
            <h2 id="quick-event-title">Создать мероприятие</h2>
            <p class="quick-event-dialog__hint">Сначала общие сведения, затем — пожелания по времени. Бронь не создаётся.</p></div>
        <button type="button" class="quick-event-dialog__close" data-quick-event-close aria-label="Закрыть">×</button>
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
        <section class="quick-event-dialog__body" data-quick-step="base">
            <h3>1. О мероприятии</h3>
            <label class="quick-event-dialog__field"><span>Тип мероприятия</span>
                <span class="select-control"><select name="type" required data-quick-type>
                    @foreach(\App\Modules\Event\Domain\Enums\EventTypeEnum::cases() as $kind)
                        <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                    @endforeach
                </select></span>
            </label>
            <label class="quick-event-dialog__field"><span>Название <small>можно изменить</small></span>
                <input type="text" name="title" maxlength="150" minlength="2" placeholder="Например, играем 3×3" data-quick-title>
            </label>
            <label class="quick-event-dialog__field"><span>Площадка</span>
                <span class="select-control"><select name="venue_id" required data-quick-venue>
                    <option value="">Выбери площадку</option>
                    @foreach($quickVenues as $place)
                        <option value="{{ $place->id }}" @selected($quickSelectedVenue === (int) $place->id)>{{ $place->name }}</option>
                    @endforeach
                </select></span>
            </label>
            <label class="quick-event-dialog__field" data-quick-game-fields><span>Формат игры</span>
                <span class="select-control"><select name="game_format" data-quick-format>
                    <option value="streetball_3x3">3×3</option>
                    <option value="basketball_5x5">5×5</option>
                    <option value="streetball_1x1">1×1</option>
                </select></span>
            </label>
            <label class="quick-event-dialog__field"><span>Видимость</span>
                <span class="select-control"><select name="visibility" required>
                    <option value="public">Открытое для всех</option>
                    <option value="private">По приглашению</option>
                </select></span>
            </label>
            <label class="quick-event-dialog__field"><span>Максимум участников</span>
                <input type="number" name="max_participants" min="2" max="500" placeholder="Без ограничения"></label>
            <label class="quick-event-dialog__field"><span>Описание</span>
                <textarea name="description" rows="3" maxlength="5000"
                    placeholder="Кого ждёшь и как будет проходить мероприятие?"></textarea></label>
        </section>
        <section class="quick-event-dialog__body" data-quick-step="schedule" hidden>
            <h3>2. Желаемая дата и время <small>необязательно</small></h3>
            <p class="quick-event-dialog__hint">Можешь пропустить этот шаг. Выбранные интервалы — предварительные пожелания, не бронь. Даже если слот уже занят, можно отправить менеджеру запрос на согласование.</p>
            <label class="quick-event-dialog__field"><span>Зал или корт</span>
                <span class="select-control"><select name="venue_court_id" data-quick-court>
                    <option value="">Основной зал</option>
                </select></span></label>
            <div class="quick-event-dialog__grid">
                <label class="quick-event-dialog__field"><span>Дата</span>
                    <input type="date" data-quick-date min="{{ now(config('app.timezone','Europe/Moscow'))->format('Y-m-d') }}"></label>
                <label class="quick-event-dialog__field"><span>Время начала</span>
                    <input type="time" data-quick-time step="1800"></label>
            </div>
            <div class="quick-event-dialog__grid">
                <label class="quick-event-dialog__field"><span>Продолжительность</span>
                    <span class="select-control"><select data-quick-duration>
                        @foreach(range(30,480,30) as $duration)
                            <option value="{{ $duration }}" @selected($duration === 90)>{{ $duration }} мин.</option>
                        @endforeach
                    </select></span></label>
                <div class="quick-event-dialog__schedule-buttons">
                    <button type="button" class="{{ $quickSecondary }}" data-quick-show-schedule>Посмотреть занятость</button>
                    <button type="button" class="{{ $quickPrimary }}" data-quick-add-slot>Добавить время</button>
                </div>
            </div>
            <div class="quick-event-dialog__availability" data-quick-availability hidden aria-live="polite"></div>
            <h4>Выбранные интервалы</h4>
            <ol class="quick-event-dialog__slots" data-quick-slots></ol>
            <p class="quick-event-dialog__hint" data-quick-no-slots>Пока ничего не выбрано. Можно перейти дальше без даты.</p>
            <fieldset class="quick-event-dialog__mode" data-quick-mode-group hidden>
                <legend>Что означают эти интервалы?</legend>
                <label><input type="radio" name="schedule_mode" value="alternatives" checked>
                    <span>Это разные варианты времени <strong>одного мероприятия</strong> — согласуем один</span></label>
                <label><input type="radio" name="schedule_mode" value="separate">
                    <span>Это <strong>несколько отдельных мероприятий</strong> — по одному на каждый интервал</span></label>
            </fieldset>
        </section>
        <section class="quick-event-dialog__body" data-quick-step="review" hidden>
            <h3>3. Подтверждение</h3>
            <div data-quick-review></div>
            <p class="quick-event-dialog__hint"><strong>Важно:</strong> создание мероприятия или предварительной заявки не является бронированием. Менеджер площадки согласует время и условия отдельно.</p>
            <p data-quick-feedback role="alert" hidden></p>
        </section>
        <footer class="quick-event-dialog__actions">
            <button type="button" class="{{ $quickSecondary }}" data-quick-back hidden>Назад</button>
            <button type="button" class="{{ $quickSecondary }}" data-quick-event-close>Отмена</button>
            <button type="button" class="{{ $quickPrimary }}" data-quick-next>Далее</button>
            <button type="submit" class="{{ $quickPrimary }}" data-quick-submit hidden>Создать мероприятие</button>
        </footer>
    </form>
</dialog>
<dialog class="quick-event-auth" data-quick-auth-info aria-labelledby="quick-event-auth-title">
    <header><h2 id="quick-event-auth-title">Нужна авторизация</h2>
        <button type="button" data-quick-auth-close aria-label="Закрыть">×</button></header>
    <div class="quick-event-auth__content">
        <p>Чтобы создать мероприятие, необходимо войти или зарегистрироваться. Все введённые данные и выбранные интервалы сохранятся. После входа мы завершим создание и откроем мероприятие.</p>
        <div class="quick-event-dialog__actions">
            <button type="button" class="{{ $quickSecondary }}" data-quick-auth-close>Позже</button>
            <button type="button" class="{{ $quickPrimary }}" data-quick-auth-login>Войти</button>
        </div>
    </div>
</dialog>
@once
<style>
.quick-event-dialog,.quick-event-auth{width:min(670px,calc(100vw - 24px));max-width:100%;max-height:94dvh;overflow:auto;padding:0;border:1px solid var(--line,#59616c);border-radius:16px;background:var(--surface,#1d2027);color:var(--text,#f5f6f8);box-shadow:0 22px 80px #0008}
.quick-event-dialog::backdrop,.quick-event-auth::backdrop{background:rgba(0,0,0,.65)}
.quick-event-dialog__heading,.quick-event-auth header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;padding:18px 22px;border-bottom:1px solid var(--line,#606772)}
.quick-event-dialog__heading h2,.quick-event-auth h2{font-size:clamp(20px,2vw,26px);margin:0}
.quick-event-dialog__eyebrow{margin:0 0 5px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.7}
.quick-event-dialog__close,.quick-event-auth header button{border:0;color:inherit;background:none;cursor:pointer;font-size:30px;line-height:1}
.quick-event-dialog__body,.quick-event-auth__content{display:grid;gap:14px;padding:20px 22px}
.quick-event-dialog__body[hidden],.quick-event-dialog__actions>[hidden],.quick-event-dialog__mode[hidden]{display:none}
.quick-event-dialog__body h3,.quick-event-dialog__body h4{margin:0}
.quick-event-dialog__hint{font-size:13px;line-height:1.5;margin:0;opacity:.8}
.quick-event-dialog__field{display:grid;gap:7px}
.quick-event-dialog__field>span{font-weight:600}
.quick-event-dialog__field input,.quick-event-dialog__field select,.quick-event-dialog__field textarea{box-sizing:border-box;width:100%;min-width:0;min-height:43px;border:1px solid var(--line,#6b717a);border-radius:8px;padding:8px 11px;background:var(--field,#292e35);color:inherit}
.quick-event-dialog__field textarea{min-height:85px;resize:vertical}
.quick-event-dialog__field [aria-invalid=true]{border-color:#ff6b6b}
.quick-event-dialog__error{color:#ff9696;font-size:12px}
.quick-event-dialog__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.quick-event-dialog__schedule-buttons{display:flex;align-items:end;gap:7px;flex-wrap:wrap}
.quick-event-dialog__availability{display:flex;gap:6px;flex-wrap:wrap;padding:12px;border:1px solid var(--line,#606772);border-radius:9px}
.quick-event-dialog__availability[hidden]{display:none}
.quick-event-dialog__availability button{padding:7px;border:1px solid var(--line,#606772);border-radius:6px;color:inherit;background:var(--field,#333)}
.quick-event-dialog__availability button.is-busy{border-color:#d59b56;opacity:.85}
.quick-event-dialog__slots{margin:0;display:grid;gap:8px;padding-left:22px}
.quick-event-dialog__slots li{display:flex;align-items:center;gap:10px;justify-content:space-between;border-bottom:1px solid var(--line,#555);padding:7px}
.quick-event-dialog__mode{display:grid;gap:10px;border:1px solid var(--line,#666);border-radius:10px;padding:12px}
.quick-event-dialog__mode legend{font-weight:600}
.quick-event-dialog__mode label{display:flex;gap:9px;align-items:flex-start;cursor:pointer}
.quick-event-dialog__mode input{margin-top:4px}
.quick-event-dialog__actions{display:flex;gap:8px;justify-content:flex-end;padding:17px 22px;flex-wrap:wrap;border-top:1px solid var(--line,#606772)}
@media(max-width:530px){.quick-event-dialog__heading,.quick-event-dialog__body,.quick-event-dialog__actions{padding-inline:14px}.quick-event-dialog__grid{grid-template-columns:1fr}}
</style>
@endonce
