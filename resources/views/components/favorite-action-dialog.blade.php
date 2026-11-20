@php
    $isAppTheme = app(\App\Presentation\Theming\ThemeResolver::class)->active() === 'mskba_app';
@endphp
<dialog class="mskba-modal venue-main-favorite-dialog {{ $isAppTheme ? 'venue-main-favorite-dialog--app' : '' }}" data-favorite-action-dialog aria-labelledby="favorite-action-title">
    <header class="mskba-modal__header venue-main-favorite-dialog__header">
        <h2 id="favorite-action-title">Избранное</h2>
        <button type="button" class="icon-button venue-main-favorite-dialog__close" data-favorite-action-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg></button>
    </header>
    <div class="mskba-modal__body venue-main-favorite-dialog__body"><p data-favorite-action-text></p></div>
    <footer class="mskba-modal__footer venue-main-favorite-dialog__actions">
        <button type="button" class="{{ $isAppTheme ? 'button primary' : 'btn btn--primary' }}" data-favorite-action-submit>Понятно</button>
    </footer>
</dialog>
@if (!$isAppTheme)
    <button type="button" hidden class="js-handler" data-handler="modal" data-modal-action="open" data-modal-target="auth-entry-classic" data-favorite-classic-auth></button>
@endif
