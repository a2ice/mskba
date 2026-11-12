<dialog id="{{ $dialogId }}" class="mskba-modal app-profile__request-dialog"
    data-confirm-missing-dialog data-confirm-missing-target="{{ $formId }}"
    aria-labelledby="{{ $dialogId }}-heading">
    <div class="mskba-modal__header app-profile__request-header">
        <h2 id="{{ $dialogId }}-heading" tabindex="-1">{{ $heading }}</h2>
        <button type="button" class="app-profile__dialog-close" data-confirm-missing-close aria-label="Закрыть">×</button>
    </div>
    <div class="mskba-modal__body mskba-scroll app-profile__request-body">
        <p>Для подтверждения необходимо указать и сохранить следующие данные:</p>
        <ul data-confirm-missing-list></ul>
    </div>
    <div class="mskba-modal__footer app-profile__request-footer">
        <button type="button" class="button primary" data-confirm-missing-close>Понятно</button>
    </div>
</dialog>
