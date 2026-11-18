@php
    $quickApp = app(\App\Presentation\Theming\ThemeResolver::class)->active() === 'mskba_app';
    $quickPrimary = $quickApp ? 'button primary' : 'btn btn--primary';
    $quickSecondary = $quickApp ? 'button secondary' : 'btn btn--secondary';
    $quickVenues = \App\Modules\Venue\Domain\Models\Venue::query()
        ->where('status', 'confirmed')->where('is_published', true)
        ->orderBy('name')->get(['id', 'name']);
    $quickSelectedVenue = (int) ($quickEventVenueId ?? 0);
@endphp
<dialog class="quick-event-dialog {{ $quickApp ? 'quick-event-dialog--app' : 'quick-event-dialog--dark' }}"
    data-quick-event-dialog aria-labelledby="quick-event-title">
    <div class="quick-event-dialog__heading">
        <div>
            <p class="quick-event-dialog__eyebrow">MSKBA · мероприятия</p>
            <h2 id="quick-event-title">Создать мероприятие</h2>
            <p class="quick-event-dialog__hint">Организатор — вы. Участники смогут откликнуться и обсудить детали на странице мероприятия.</p>
        </div>
        <button type="button" class="quick-event-dialog__close" data-quick-event-close aria-label="Закрыть">×</button>
    </div>
    @guest
        <div class="quick-event-dialog__body">
            <p>Для создания мероприятия войдите в аккаунт или зарегистрируйтесь.</p>
            <a class="{{ $quickPrimary }}" href="{{ route('events.wizard', array_filter(['venue_id' => $quickSelectedVenue ?: null])) }}">Войти и создать</a>
        </div>
    @else
        <form method="POST" action="{{ route('events.store') }}" class="quick-event-dialog__form" data-quick-event-form>
            @csrf
            <input type="hidden" name="event_request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <input type="hidden" name="booking_scope" value="whole">
            <input type="hidden" name="publish_to_telegram" value="0">
            <input type="hidden" name="send_pre_request" value="0">
            <input type="hidden" name="game_recruitment_mode" value="individual_draft">
            <input type="hidden" name="game_accepts_applications" value="1">
            <input type="hidden" name="side_a_size" value="3" data-quick-side-a>
            <input type="hidden" name="side_b_size" value="3" data-quick-side-b>
            <input type="hidden" name="scoring_type" value="streetball" data-quick-scoring>
            <input type="hidden" name="timing_mode" value="whole_game" data-quick-timing>
            <input type="hidden" name="periods_count" value="4">
            <div class="quick-event-dialog__body">
                <label class="quick-event-dialog__field">
                    <span>Тип мероприятия</span>
                    @if($quickApp)<span class="select-control">@endif
                    <select name="type" class="{{ $quickApp ? '' : 'form-select' }}" required data-quick-type>
                        @foreach(\App\Modules\Event\Domain\Enums\EventTypeEnum::cases() as $kind)
                            <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                        @endforeach
                    </select>
                    @if($quickApp)<svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.8"/></svg></span>@endif
                </label>
                <label class="quick-event-dialog__field">
                    <span>Название</span>
                    <input type="text" name="title" maxlength="150" minlength="2" placeholder="Например, играем 3×3 в субботу"
                        class="{{ $quickApp ? '' : 'form-control' }}" required>
                </label>
                <label class="quick-event-dialog__field">
                    <span>Площадка</span>
                    @if($quickApp)<span class="select-control">@endif
                    <select name="venue_id" class="{{ $quickApp ? '' : 'form-select' }}"
                        required data-quick-venue>
                        <option value="">Выберите площадку</option>
                        @foreach($quickVenues as $place)
                            <option value="{{ $place->id }}" @selected($quickSelectedVenue === (int) $place->id)>{{ $place->name }}</option>
                        @endforeach
                    </select>
                    @if($quickApp)<svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.8"/></svg></span>@endif
                </label>
                <div class="quick-event-dialog__grid">
                    <label class="quick-event-dialog__field">
                        <span>Дата и время начала</span>
                        <input type="datetime-local" name="starts_at" required class="{{ $quickApp ? '' : 'form-control' }}"
                            min="{{ now(config('app.timezone', 'Europe/Moscow'))->format('Y-m-d\TH:i') }}">
                    </label>
                    <label class="quick-event-dialog__field">
                        <span>Продолжительность</span>
                        @if($quickApp)<span class="select-control">@endif
                    <select name="duration_minutes" required class="{{ $quickApp ? '' : 'form-select' }}">
                            @foreach(range(30, 480, 30) as $duration)
                                <option value="{{ $duration }}" @selected($duration === 90)>{{ $duration }} мин.</option>
                            @endforeach
                        </select>
                    @if($quickApp)<svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.8"/></svg></span>@endif
                    </label>
                </div>
                <label class="quick-event-dialog__field" data-quick-game-fields>
                    <span>Формат игры</span>
                    @if($quickApp)<span class="select-control">@endif
                    <select name="game_format" class="{{ $quickApp ? '' : 'form-select' }}" data-quick-format>
                        <option value="streetball_3x3">3×3</option>
                        <option value="basketball_5x5">5×5</option>
                        <option value="streetball_1x1">1×1</option>
                    </select>
                    @if($quickApp)<svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.8"/></svg></span>@endif
                </label>
                <div class="quick-event-dialog__grid">
                    <label class="quick-event-dialog__field">
                        <span>Видимость</span>
                        @if($quickApp)<span class="select-control">@endif
                    <select name="visibility" required class="{{ $quickApp ? '' : 'form-select' }}">
                            <option value="public">Открытое для всех</option>
                            <option value="private">По приглашению</option>
                        </select>
                    @if($quickApp)<svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.8"/></svg></span>@endif
                    </label>
                    <label class="quick-event-dialog__field">
                        <span>Максимум участников</span>
                        <input type="number" name="max_participants" min="2" max="500"
                            placeholder="Без ограничения" class="{{ $quickApp ? '' : 'form-control' }}">
                    </label>
                </div>
                <label class="quick-event-dialog__field">
                    <span>Описание</span>
                    <textarea name="description" rows="3" maxlength="5000"
                        class="{{ $quickApp ? '' : 'form-control' }}" placeholder="Расскажите, кого ждёте и как будет проходить игра"></textarea>
                </label>
                <p class="quick-event-dialog__hint">Публикация не означает бронирование площадки. Для аренды используйте существующий процесс после согласования с участниками. Место и время пока обязательны.</p>
            </div>
            <div class="quick-event-dialog__actions">
                <a class="{{ $quickSecondary }}" href="{{ $quickApp ? route('events.create') : route('events.wizard') }}">Расширенная форма</a>
                <button type="button" class="{{ $quickSecondary }}" data-quick-event-close>Отмена</button>
                <button type="submit" class="{{ $quickPrimary }}">Создать мероприятие</button>
            </div>
        </form>
    @endguest
</dialog>
@once
<style>
.quick-event-dialog {width:min(640px, calc(100vw - 24px));max-width:100%;max-height:min(94dvh, 900px);overflow:auto;padding:0;border:1px solid var(--line, #545b63);border-radius:18px;background:var(--surface, #181c23);color:var(--text-color, inherit);box-shadow:0 24px 85px #0008}
.quick-event-dialog--app {background:var(--surface, #141618);color:var(--text, #f5f6f7)}
.quick-event-dialog::backdrop {background:rgba(0,0,0,.64)}
.quick-event-dialog__heading {display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:22px 24px 14px;border-bottom:1px solid var(--line, #7774)}
.quick-event-dialog__heading h2 {margin:2px 0 6px;font-size:clamp(20px,2vw,26px)}
.quick-event-dialog__eyebrow {font-size:12px;text-transform:uppercase;letter-spacing:.1em;opacity:.68}
.quick-event-dialog__close {cursor:pointer;border:0;background:transparent;color:inherit;font-size:32px;line-height:1;padding:0 5px}
.quick-event-dialog__body {display:grid;gap:16px;padding:22px 24px}
.quick-event-dialog__field {display:grid;gap:7px;min-width:0}
.quick-event-dialog__field>span {font-weight:600;font-size:14px}
.quick-event-dialog__field input,.quick-event-dialog__field select,.quick-event-dialog__field textarea {width:100%;min-width:0;max-width:100%;min-height:42px;border-radius:8px;padding:9px 12px;box-sizing:border-box}
.quick-event-dialog__field textarea {min-height:85px;resize:vertical}
.quick-event-dialog__field input,.quick-event-dialog__field select,.quick-event-dialog__field textarea {border:1px solid var(--control-line, #8b949e);background:var(--field, #252a31);color:inherit}
.quick-event-dialog--app .quick-event-dialog__field input,.quick-event-dialog--app .quick-event-dialog__field select,.quick-event-dialog--app .quick-event-dialog__field textarea {background:var(--field, #0e1012);color:var(--text, #f5f6f7)}
.quick-event-dialog__grid {display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.quick-event-dialog__hint {margin:0;font-size:13px;line-height:1.5;opacity:.75}
.quick-event-dialog__actions {display:flex;justify-content:flex-end;gap:12px;padding:16px 24px 22px}
@media(max-width:540px){.quick-event-dialog__grid {grid-template-columns:1fr}.quick-event-dialog__heading,.quick-event-dialog__body {padding-inline:16px}.quick-event-dialog__actions {padding-inline:16px;flex-wrap:wrap}}
</style>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const dialog = document.querySelector('[data-quick-event-dialog]');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    const venue = dialog.querySelector('[data-quick-venue]');
    const type = dialog.querySelector('[data-quick-type]');
    const format = dialog.querySelector('[data-quick-format]');
    const game = dialog.querySelector('[data-quick-game-fields]');
    const syncFormat = () => {
        const preset = {
            streetball_3x3: ['3','streetball','whole_game'],
            basketball_5x5: ['5','basketball','periods'],
            streetball_1x1: ['1','streetball','whole_game'],
        }[format?.value] || ['3','streetball','whole_game'];
        dialog.querySelector('[data-quick-side-a]').value = preset[0];
        dialog.querySelector('[data-quick-side-b]').value = preset[0];
        dialog.querySelector('[data-quick-scoring]').value = preset[1];
        dialog.querySelector('[data-quick-timing]').value = preset[2];
    };
    const syncType = () => {if(game) game.hidden = type?.value !== 'game';};
    type?.addEventListener('change', syncType);
    format?.addEventListener('change', syncFormat);
    syncType();syncFormat();
    document.querySelectorAll('[data-quick-event-open]').forEach(trigger => {
        trigger.addEventListener('click', event => {
            event.preventDefault();
            const id = trigger.dataset.quickEventVenueId;
            if (id && venue?.querySelector('option[value="' + CSS.escape(id) + '"]')) venue.value = id;
            document.querySelector('[data-venue-choice-dialog][open]')?.close();
            dialog.showModal();
            dialog.querySelector('[name="title"]')?.focus();
        });
    });
    dialog.querySelectorAll('[data-quick-event-close]').forEach(b=>b.addEventListener('click',()=>dialog.close()));
    let downOutside=false;
    dialog.addEventListener('pointerdown',e=>{downOutside=e.target===dialog;});
    dialog.addEventListener('pointerup',e=>{if(downOutside&&e.target===dialog)dialog.close();downOutside=false;});
});
</script>
@endonce
