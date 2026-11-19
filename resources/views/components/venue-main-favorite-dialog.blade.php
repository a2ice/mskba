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
        <h2 id="venue-main-favorite-title" data-venue-favorite-title>Площадка теперь в избранном</h2>
        <button type="button" class="icon-button venue-main-favorite-dialog__close" data-venue-favorite-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg></button>
    </header>
    <div class="mskba-modal__body venue-main-favorite-dialog__body">
        <p data-venue-favorite-description>Добавь площадку в избранное, чтобы вернуться к ней позже.</p>
        <p class="venue-main-favorite-dialog__error" data-venue-favorite-error role="alert" hidden></p>
    </div>
    <footer class="mskba-modal__footer venue-main-favorite-dialog__actions">
        <button type="button" class="{{ $primaryButton }}" data-venue-favorite-submit>Понятно</button>
    </footer>
</dialog>
@once
<style>
.venue-main-favorite-dialog{width:min(470px,calc(100vw - 24px));max-width:calc(100vw - 24px);max-height:90dvh;padding:0;color:var(--text,#f5f6f8)}

.venue-main-favorite-dialog[open]{display:flex;flex-direction:column}
.venue-main-favorite-dialog:not([open]){display:none}
.venue-main-favorite-dialog:not(.venue-main-favorite-dialog--app){border:1px solid var(--line,#646a72);border-radius:16px;background:var(--surface,#1d2027);box-shadow:0 20px 85px #0008}
.venue-main-favorite-dialog:not(.venue-main-favorite-dialog--app)::backdrop{background:rgba(0,0,0,.64)}
.venue-main-favorite-dialog:not(.venue-main-favorite-dialog--app)[open]>.venue-main-favorite-dialog__header::before{content:"⠿";align-self:center;justify-self:center;color:var(--muted,#929aa4);font-size:1rem;line-height:1;pointer-events:none}
.venue-main-favorite-dialog__header{display:grid;grid-template-columns:16px minmax(0,1fr) 44px;align-items:center;gap:12px;padding:18px 20px;border-bottom:1px solid var(--line,#646a72);cursor:grab;touch-action:none;user-select:none}
.venue-main-favorite-dialog__header h2{font-size:21px;line-height:1.3;margin:0;min-width:0;overflow-wrap:anywhere}
.venue-main-favorite-dialog__header .venue-main-favorite-dialog__close{width:44px;height:44px;display:flex;align-items:center;justify-content:center;justify-self:end;border:1px solid var(--line,#646a72);border-radius:12px;background:var(--surface,#1d2027);color:inherit;cursor:pointer}
.venue-main-favorite-dialog__close svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round}
.venue-main-favorite-dialog__body{padding:18px 20px;line-height:1.55}
.venue-main-favorite-dialog__error{color:#f29b9b}
.venue-main-favorite-dialog__actions{display:flex;justify-content:flex-end;gap:9px;flex-wrap:wrap;padding:15px 20px 20px;border-top:1px solid var(--line,#646a72)}
@media(max-width:480px){.venue-main-favorite-dialog__header{padding:16px}.venue-main-favorite-dialog__body{padding:18px 16px}.venue-main-favorite-dialog__actions{padding:16px}}
</style>
@endonce
