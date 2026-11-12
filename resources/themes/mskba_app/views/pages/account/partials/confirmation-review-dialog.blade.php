<dialog id="{{ $dialogId }}" class="mskba-modal app-profile__request-dialog"
    data-confirm-review-dialog data-confirm-review-target="{{ $formId }}"
    aria-labelledby="{{ $dialogId }}-heading">
    <div class="mskba-modal__header app-profile__request-header">
        <h2 id="{{ $dialogId }}-heading" tabindex="-1">{{ $heading }}</h2>
        <button type="button" class="app-profile__dialog-close" data-confirm-review-close aria-label="Закрыть">×</button>
    </div>
    <div class="mskba-modal__body mskba-scroll app-profile__request-body">
        <p>Уверен, что ввёл данные верно? Подтверждаются следующие значения:</p>
        <ul>
            @foreach($values as $label => $value)
                <li><strong>{{ $label }}:</strong> {{ filled($value) ? $value : 'Не указано' }}</li>
            @endforeach
        </ul>
        <p><strong>После подтверждения некоторые данные можно будет изменить только по запросу с указанием причины.</strong></p>
    </div>
    <div class="mskba-modal__footer app-profile__request-footer">
        <button type="button" class="button secondary" data-confirm-review-close>Вернуться</button>
        <button type="button" class="button primary" data-confirm-review-accept>Подтвердить данные</button>
    </div>
</dialog>
