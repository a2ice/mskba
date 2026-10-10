<dialog class="mskba-modal app-context-placeholder-dialog" data-context-placeholder-dialog aria-labelledby="context-placeholder-title">
    <header class="mskba-modal__header app-context-dialog__header">
        <h2 id="context-placeholder-title" tabindex="-1" data-context-placeholder-title>Функция в разработке</h2>
        <button type="button" data-context-placeholder-close aria-label="Закрыть">×</button>
    </header>
    <div class="mskba-modal__body app-context-dialog__content">
        <p>Эта возможность скоро появится. Пока это демонстрация контекстного действия.</p>
    </div>
</dialog>

<dialog class="mskba-modal app-context-help-dialog" data-context-help-dialog
        data-help-url="{{ route('faq.help-content') }}"
        data-question-url="{{ route('faq.questions.store') }}"
        aria-labelledby="context-help-title">
    <header class="mskba-modal__header app-context-dialog__header">
        <h2 id="context-help-title" tabindex="-1">Помощь и FAQ</h2>
        <button type="button" data-context-help-close aria-label="Закрыть">×</button>
    </header>
    <div class="mskba-modal__body mskba-scroll app-context-help__body">
        <label class="app-context-help__lookup" for="context-help-section">Раздел FAQ
            <input type="search" id="context-help-section" data-help-search list="context-help-options"
                   placeholder="Выберите раздел или начните вводить название" autocomplete="off">
            <datalist id="context-help-options" data-help-options></datalist>
        </label>
        <nav class="app-context-help__breadcrumbs" aria-label="Навигация внутри FAQ" data-help-breadcrumbs></nav>
        <div class="app-context-help__articles" data-help-articles role="status" aria-live="polite">Загружаем ответы…</div>
        <details class="app-context-help__ask" data-help-ask>
            <summary>Задать вопрос</summary>
            <div class="app-context-help__ask-body">
                <div data-help-guest hidden>
                    <p>Чтобы задать вопрос, необходимо авторизоваться.</p>
                    <button type="button" class="button primary" data-help-auth>Авторизоваться</button>
                </div>
                <form data-help-form hidden>
                    <label>Тема вопроса
                        <select name="topic" required>
                            <option value="">Выберите тему</option>
                            @foreach (config('support.question_topics', []) as $topic => $label)
                                <option value="{{ $topic }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Ваш вопрос
                        <textarea name="message" rows="5" required minlength="10" maxlength="5000" placeholder="Опишите ситуацию и что нужно уточнить"></textarea>
                    </label>
                    <p role="status" aria-live="polite" data-help-form-status></p>
                    <button type="submit" class="button primary">Отправить вопрос</button>
                </form>
                <p class="app-context-help__mail">Также можно написать на <a href="mailto:{{ config('support.email') }}">{{ config('support.email') }}</a>.</p>
            </div>
        </details>
    </div>
</dialog>
