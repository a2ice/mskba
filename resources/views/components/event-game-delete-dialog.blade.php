@php
    $planId = $planId ?? 'event-game-plan';
    $planApp = $planApp ?? true;
    $planPrimary = $planApp ? 'button primary' : 'btn btn--primary';
    $planSecondary = $planApp ? 'button secondary' : 'btn btn--secondary';
@endphp
<dialog class="mskba-modal event-game-editor {{ $planApp ? 'event-game-editor--app' : 'event-game-editor--legacy' }}" id="{{ $planId }}-delete" data-game-plan-delete-dialog aria-labelledby="{{ $planId }}-delete-title">
    <header class="mskba-modal__header event-game-editor__header">
        <h2 id="{{ $planId }}-delete-title">Удалить игру?</h2>
        <button type="button" class="icon-button" data-game-plan-delete-cancel aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    </header>
    <div class="mskba-modal__body mskba-scroll event-game-editor__body">
        <p>Вы уверены, что хотите удалить игру из мероприятия?</p>
        <strong data-game-plan-delete-name></strong>
        <p class="event-game-editor__hint" data-game-plan-delete-info></p>
        <p class="event-game-editor__error" data-game-plan-delete-error role="alert" hidden></p>
    </div>
    <footer class="mskba-modal__footer event-game-editor__footer">
        <button type="button" class="{{ $planSecondary }}" data-game-plan-delete-cancel>Отмена</button>
        <button type="button" class="{{ $planPrimary }} event-game-plan__danger" data-game-plan-delete-confirm>Удалить</button>
    </footer>
</dialog>
