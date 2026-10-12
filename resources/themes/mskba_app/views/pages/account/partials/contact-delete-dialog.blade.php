<dialog class="mskba-modal app-contact-wizard__dialog app-account-contacts__delete-dialog"
        data-contact-delete-dialog aria-labelledby="contact-delete-title"
        aria-describedby="contact-delete-question">
    <header class="mskba-modal__header mskba-auth-top app-contact-wizard__header">
        <h2 id="contact-delete-title" class="eyebrow accent mskba-auth-heading">Удалить контакт</h2>
        <button type="button" class="icon-button mskba-auth-close app-contact-wizard__close"
                data-contact-delete-cancel aria-label="Закрыть окно">
            <svg aria-hidden="true"><use href="#close"/></svg>
        </button>
    </header>
    <div class="mskba-modal__body app-contact-wizard__body">
        <p id="contact-delete-question" class="app-account-contacts__delete-question">
            Вы уверены, что хотите удалить контакт?
        </p>
        <p class="app-account-contacts__delete-target" data-contact-delete-target></p>
    </div>
    <footer class="mskba-modal__footer app-contact-wizard__footer app-account-contacts__delete-footer">
        <button type="button" class="button secondary" data-contact-delete-cancel>Отмена</button>
        <button type="button" class="button danger" data-contact-delete-confirm>Удалить контакт</button>
    </footer>
</dialog>
