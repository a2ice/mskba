<dialog class="mskba-modal venue-play-dialog venue-play-delete-dialog {{ $isAppTheme ? 'venue-play-dialog--app' : '' }}" data-venue-play-delete-dialog aria-labelledby="venue-play-delete-title" aria-describedby="venue-play-delete-question">
    <header class="mskba-modal__header venue-play-dialog__header">
        <h2 id="venue-play-delete-title">Удалить сообщение</h2>
        <button type="button" class="icon-button venue-play-dialog__close" data-venue-play-delete-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12M6 6l12 12"/></svg></button>
    </header>
    <div class="mskba-modal__body venue-play-dialog__body">
        <p id="venue-play-delete-question">Вы уверены, что хотите удалить?</p>
        <p data-venue-play-delete-error role="alert" hidden></p>
    </div>
    <footer class="mskba-modal__footer venue-play-dialog__footer">
        <button type="button" class="{{ $isAppTheme ? 'button danger' : 'btn btn--danger' }}" data-venue-play-delete-confirm>Да, удалить</button>
    </footer>
</dialog>
