<dialog id="account-confirmation-guide-dialog"
        class="mskba-modal app-account-confirm-dialog"
        data-account-confirmation-guide-dialog
        aria-labelledby="account-confirmation-guide-heading">
    <div class="mskba-modal__header app-account-confirm-dialog__header">
        <h2 id="account-confirmation-guide-heading" tabindex="-1">Как подтвердить аккаунт</h2>
        <button type="button" class="app-account-confirm-dialog__close"
                data-account-confirmation-guide-close aria-label="Закрыть">×</button>
    </div>
    <div class="mskba-modal__body mskba-scroll app-account-confirm-dialog__body">
        @if ($accountConfirmationGuideHtml !== null)
            {!! $accountConfirmationGuideHtml !!}
        @else
            <p>Инструкция по подтверждению аккаунта сейчас недоступна.</p>
        @endif
    </div>
    <div class="mskba-modal__footer app-account-confirm-dialog__footer">
        <button type="button" class="button secondary" data-account-confirmation-guide-close>Понятно</button>
    </div>
</dialog>
