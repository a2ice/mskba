<dialog id="app-player-logo-dialog" class="mskba-modal app-player-workspace__logo-dialog" data-player-logo-dialog
    data-list-url="{{ route('account.player-character.logos.index') }}"
    data-upload-url="{{ route('account.player-character.logos.store') }}"
    aria-labelledby="app-player-logo-title">
    <div class="mskba-modal__header app-profile__request-header">
        <h2 id="app-player-logo-title" tabindex="-1">Выбрать логотип</h2>
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
<dialog id="app-player-logo-view-dialog" class="mskba-modal app-player-workspace__logo-view-dialog"
    data-player-logo-view-dialog aria-labelledby="app-player-logo-view-title">
    <div class="mskba-modal__header app-profile__request-header">
        <h2 id="app-player-logo-view-title" tabindex="-1">Логотип команды</h2>
        <button type="button" class="app-profile__dialog-close" data-player-logo-view-close
            aria-label="Закрыть">×</button>
    </div>
    <div class="mskba-modal__body app-player-workspace__logo-view-body">
        <img data-player-logo-view-image alt="Логотип команды">
    </div>
</dialog>
