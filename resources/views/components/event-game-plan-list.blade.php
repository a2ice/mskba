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
@include('components.event-game-delete-dialog', ['planId' => $planId, 'planApp' => $planApp])
@include('components.event-game-plan-styles')
