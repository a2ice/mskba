@php
    $isAppTheme = app(\App\Presentation\Theming\ThemeResolver::class)->active() === 'mskba_app';
    $primaryButton = $isAppTheme ? 'button primary' : 'btn btn--primary';
    $secondaryButton = $isAppTheme ? 'button secondary' : 'btn btn--secondary';
@endphp
<dialog class="venue-main-favorite-dialog" data-venue-favorite-dialog
    data-venue-id="{{ $venue->id }}"
    data-status-url="{{ route('favorites.venue-statuses') }}"
    data-toggle-url="{{ route('favorites.toggle') }}"
    aria-labelledby="venue-main-favorite-title">
    <header class="venue-main-favorite-dialog__header">
        <h2 id="venue-main-favorite-title" data-venue-favorite-title>Избранное</h2>
        <button type="button" data-venue-favorite-close aria-label="Закрыть">×</button>
    </header>
    <div class="venue-main-favorite-dialog__body">
        <p data-venue-favorite-description>Добавь площадку в избранное, чтобы вернуться к ней позже.</p>
        <p class="venue-main-favorite-dialog__error" data-venue-favorite-error role="alert" hidden></p>
    </div>
    <footer class="venue-main-favorite-dialog__actions">
        <button type="button" class="{{ $secondaryButton }}" data-venue-favorite-cancel hidden>Оставить в избранном</button>
        <button type="button" class="{{ $primaryButton }}" data-venue-favorite-submit>Понятно</button>
    </footer>
</dialog>
@once
<style>
.venue-main-favorite-dialog{width:min(430px,calc(100vw - 24px));max-width:100%;border:1px solid var(--line,#646a72);border-radius:16px;padding:0;background:var(--surface,#1d2027);color:var(--text,#f5f6f8);box-shadow:0 20px 85px #0008}
.venue-main-favorite-dialog::backdrop{background:rgba(0,0,0,.64)}
.venue-main-favorite-dialog__header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 20px;border-bottom:1px solid var(--line,#646a72)}
.venue-main-favorite-dialog__header h2{font-size:21px;margin:0}
.venue-main-favorite-dialog__header button{font-size:28px;color:inherit;border:0;background:none;cursor:pointer}
.venue-main-favorite-dialog__body{padding:18px 20px}
.venue-main-favorite-dialog__error{color:#f29b9b}
.venue-main-favorite-dialog__actions{display:flex;justify-content:flex-end;gap:9px;flex-wrap:wrap;padding:15px 20px 20px}
</style>
@endonce
