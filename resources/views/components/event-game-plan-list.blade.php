{{-- List and confirmation dialog, mounted by the event game plan adapter. --}}
@php
    $planId = $planId ?? 'event-game-plan';
    $planApp = $planApp ?? true;
    $planPrimary = $planApp ? 'button primary' : 'btn btn--primary';
    $planSecondary = $planApp ? 'button secondary' : 'btn btn--secondary';
@endphp
<div class="event-game-plan" id="{{ $planId }}" data-game-plan>
    <div class="event-game-plan__list" data-game-plan-list></div>
    <div class="event-game-plan__trigger" data-game-plan-empty>
        <label class="check-label">
            <input type="checkbox" data-game-plan-first>
            <span>Создать игру внутри мероприятия</span>
        </label>
        <button type="button" class="icon-button event-game-plan__help" aria-label="Подробнее"
                title="Можете сразу запланировать игры, которые вы хотите провести в рамках создаваемого мероприятия или сделать это позже."
                data-game-plan-help aria-expanded="false">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 4.2 1.8c-1 .9-1.7 1.2-1.7 2.7M12 17h.01"/></svg>
        </button>
    </div>
    <p class="event-game-editor__hint" data-game-plan-help-text hidden>Можете сразу запланировать игры, которые вы хотите провести в рамках создаваемого мероприятия или сделать это позже.</p>
    <button class="event-game-plan__add" type="button" data-game-plan-add hidden>+ Добавить еще игру</button>
    <p class="event-game-editor__error" data-game-plan-error role="alert" hidden></p>
</div>
<dialog class="mskba-modal event-game-editor {{ $planApp ? 'event-game-editor--app' : 'event-game-editor--legacy' }}" data-game-plan-delete-dialog aria-labelledby="{{ $planId }}-delete-title">
    <header class="mskba-modal__header event-game-editor__header">
        <h2 id="{{ $planId }}-delete-title">Удалить игру?</h2>
        <button type="button" class="icon-button" data-game-plan-delete-cancel aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    </header>
    <div class="mskba-modal__body mskba-scroll event-game-editor__body">
        <p>Вы уверены, что хотите удалить игру из мероприятия?</p>
        <strong data-game-plan-delete-name></strong>
        <p class="event-game-editor__hint" data-game-plan-delete-info></p>
    </div>
    <footer class="mskba-modal__footer event-game-editor__footer">
        <button type="button" class="{{ $planSecondary }}" data-game-plan-delete-cancel>Отмена</button>
        <button type="button" class="{{ $planPrimary }} event-game-plan__danger" data-game-plan-delete-confirm>Удалить</button>
    </footer>
</dialog>
@once
<style>
.event-game-plan{display:grid;gap:8px;min-width:0}
.event-game-plan [hidden]{display:none!important}
.event-game-plan__list{display:grid;gap:8px}
.event-game-plan__list:empty{display:none}
.event-game-plan__trigger,.event-game-plan__trigger label{display:flex;align-items:center;gap:8px;min-width:0}
.event-game-plan__trigger label{font-weight:600;cursor:pointer}
.event-game-plan__trigger input[type=checkbox]{width:20px;height:20px;min-width:20px;min-height:20px;flex:none;margin:0;padding:0;accent-color:var(--accent,#ed782c)}
.event-game-plan__help,.event-game-plan__icon{display:grid;place-items:center;flex:none;width:36px;height:36px;padding:0}
.event-game-plan__help svg,.event-game-plan__icon svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.event-game-plan__add,.event-game-plan__name{width:fit-content;background:none;border:0;padding:0;color:var(--accent,#ed782c);font:inherit;font-weight:600;cursor:pointer;text-align:left;overflow-wrap:anywhere}
.event-game-plan__row{display:flex;align-items:flex-start;gap:6px;border:1px solid var(--line,#606772);border-radius:12px;padding:10px 12px}
.event-game-plan__details{flex:1;min-width:0;display:grid;gap:3px}
.event-game-plan__danger,.event-game-plan__remove{color:var(--danger,#d23e45)}
</style>
@endonce
