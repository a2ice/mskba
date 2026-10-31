<dialog id="app-player-uniform-dialog" class="app-player-workspace__topup-dialog" data-player-uniform-dialog aria-labelledby="app-player-uniform-title">
    <div class="app-player-workspace__topup-head"><h3 id="app-player-uniform-title">Создать свою форму</h3><button type="button" class="app-player-workspace__secondary" data-player-uniform-close>Закрыть</button></div>
    <div class="app-player-workspace__topup-body"><p>Функционал пока в разработке. Скоро можно будет создать, примерить и заказать собственную форму для команды.</p></div>
</dialog>
<dialog id="app-player-logo-dialog" class="mskba-modal app-player-workspace__logo-dialog" data-player-logo-dialog
    data-list-url="{{ route('account.player-character.logos.index') }}"
    data-upload-url="{{ route('account.player-character.logos.store') }}"
    aria-labelledby="app-player-logo-title">
    <div class="mskba-modal__header app-profile__request-header">
        <h2 id="app-player-logo-title" tabindex="-1">Мои логотипы</h2>
        <button type="button" class="app-profile__dialog-close" data-player-logo-close
            aria-label="Закрыть">×</button>
    </div>
    <div class="mskba-modal__body mskba-scroll app-profile__request-body app-player-workspace__logo-dialog-body">
        <p>До трёх логотипов; изображения сохраняются в WebP размером не более 500 px по большей стороне.</p>
        <div class="app-player-workspace__logo-gallery" data-player-logo-gallery></div>
        <label class="app-player-workspace__logo-dropzone" data-player-logo-dropzone tabindex="0">
            <span class="app-player-workspace__logo-upload-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 16V3m-5 5 5-5 5 5M4 17v3h16v-3"/></svg></span>
            <span>Загрузить логотип</span><small>Перетащите изображение сюда или нажмите для выбора</small>
            <input type="file" accept="image/jpeg,image/png,image/webp" data-player-logo-upload>
        </label>
        <p role="status" aria-live="polite" data-player-logo-status></p>
    </div>
</dialog>