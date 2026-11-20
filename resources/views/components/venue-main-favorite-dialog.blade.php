@php
    $isAppTheme = app(\App\Presentation\Theming\ThemeResolver::class)->active() === 'mskba_app';
    $primaryButton = $isAppTheme ? 'button primary' : 'btn btn--primary';
    $secondaryButton = $isAppTheme ? 'button secondary' : 'btn btn--secondary';
@endphp
<dialog class="mskba-modal venue-main-favorite-dialog {{ $isAppTheme ? 'venue-main-favorite-dialog--app' : '' }}" data-venue-favorite-dialog
    data-venue-id="{{ $venue->id }}"
    data-status-url="{{ route('favorites.venue-statuses') }}"
    data-toggle-url="{{ route('favorites.toggle') }}"
    aria-labelledby="venue-main-favorite-title">
    <header class="mskba-modal__header venue-main-favorite-dialog__header">
        <h2 id="venue-main-favorite-title" data-venue-favorite-title>Избранное</h2>
        <button type="button" class="icon-button venue-main-favorite-dialog__close" data-venue-favorite-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg></button>
    </header>
    <div class="mskba-modal__body venue-main-favorite-dialog__body">
        <p data-venue-favorite-description>Площадка добавлена в избранное.</p>
        <p class="venue-main-favorite-dialog__error" data-venue-favorite-error role="alert" hidden></p>
    </div>
    <footer class="mskba-modal__footer venue-main-favorite-dialog__actions">
        <button type="button" class="{{ $primaryButton }}" data-venue-favorite-submit>Понятно</button>
    </footer>
</dialog>
