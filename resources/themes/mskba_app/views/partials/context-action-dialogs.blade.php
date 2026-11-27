<dialog class="mskba-modal app-context-placeholder-dialog" data-context-placeholder-dialog aria-labelledby="context-placeholder-title">
    <header class="mskba-modal__header app-context-dialog__header">
        <h2 id="context-placeholder-title" data-context-placeholder-title>Функция в разработке</h2>
        <button type="button" data-context-placeholder-close aria-label="Закрыть">×</button>
    </header>
    <div class="mskba-modal__body app-context-dialog__content">
        <p>Эта возможность скоро появится. Пока это демонстрация контекстного действия.</p>
    </div>
</dialog>

<dialog class="mskba-modal app-context-help-dialog" data-context-help-dialog
        data-help-url="{{ route('faq.help-content') }}"
        data-question-url="{{ route('faq.questions.store') }}"
        data-help-page-key="{{ app(\App\Presentation\Navigation\PageFaqContextResolver::class)->pageKey() }}"
        data-help-context="{{ app(\App\Presentation\Navigation\FaqContextResolver::class)->context() }}"
        @if(request()->routeIs('venues', 'venues.collections.show', 'venues.show', 'venues.courts.show')) data-help-initial-section="venues" @endif
        aria-labelledby="context-help-title">
    <header class="mskba-modal__header app-context-dialog__header">
        <div class="app-context-help__heading">
            <button type="button" class="app-context-help__back" data-help-back aria-label="Назад" title="Назад" hidden>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 6-6 6 6 6"/></svg>
            </button>
            <h2 id="context-help-title">Помощь и FAQ</h2>
        </div>
        <button type="button" data-context-help-close aria-label="Закрыть">×</button>
    </header>
    <div class="mskba-modal__body mskba-scroll app-context-help__body">
        @if(request()->routeIs('venues', 'venues.collections.show', 'venues.show', 'venues.courts.show'))
            <template data-help-venue-create-template>
                <div class="app-context-help__venue-create" data-help-venue-create>
                    <h4>Помогите пополнить каталог площадок</h4>
                    <p>Знаете баскетбольный зал или уличную площадку, которой ещё нет в каталоге MSKBA? Добавляйте её! Мы приветствуем вклад каждого участника: так другим игрокам будет проще найти место для игры. После проверки новая площадка сможет появиться в каталоге.</p>
                    <a class="button primary" href="{{ route('venues.create') }}">Добавить площадку</a>
                </div>
            </template>
        @endif
        <section class="app-context-help__page" data-help-page hidden aria-label="Справка по текущей странице">
            <h3 class="app-context-help__page-heading" data-help-page-title></h3>
            <div class="app-context-help__tabs" data-help-page-tabs aria-label="Разделы справки">
                <button type="button" role="tab" aria-selected="true" aria-controls="context-page-help-panel" data-help-page-tab="about">Что это за страница</button>
                <button type="button" role="tab" aria-selected="false" aria-controls="context-page-help-panel" data-help-page-tab="features" tabindex="-1">Возможности</button>
                <button type="button" role="tab" aria-selected="false" aria-controls="context-page-help-panel" data-help-page-tab="related" tabindex="-1">Связанные материалы</button>
            </div>
            <div class="app-context-help__page-content app-context-help__rendered" id="context-page-help-panel"
                 data-help-page-content role="tabpanel" tabindex="0"></div>
            <button type="button" class="app-context-help__catalog-link" data-help-page-catalog>Перейти ко всем вопросам FAQ</button>
        </section>
        <div data-help-catalog>
        <div class="app-context-help__lookup" data-help-combobox>
            <input type="text" id="context-help-section" data-help-search
                   role="combobox" aria-label="Выбрать раздел FAQ" aria-autocomplete="list"
                   aria-haspopup="listbox" aria-expanded="false" aria-controls="context-help-options"
                   placeholder="Выберите раздел FAQ" autocomplete="off">
            <button type="button" class="app-context-help__arrow" data-help-toggle
                    aria-label="Показать разделы FAQ" aria-controls="context-help-options" aria-expanded="false">
                <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 7 5 5 5-5"/></svg>
            </button>
            <button type="button" class="app-context-help__clear" data-help-clear aria-label="Сбросить раздел FAQ" hidden>×</button>
            <div id="context-help-options" class="app-context-help__options" data-help-options
                 role="listbox" aria-label="Разделы FAQ" hidden></div>
        </div>
        <nav class="app-context-help__breadcrumbs" aria-label="Навигация внутри FAQ" data-help-breadcrumbs></nav>
        <div class="app-context-help__articles" data-help-articles role="status" aria-live="polite">Загружаем ответы…</div>
        </div>
        <details class="app-context-help__ask" data-help-ask>
            <summary>Задать вопрос</summary>
            <div class="app-context-help__ask-body">
                <div data-help-guest hidden>
                    <p>Чтобы задать вопрос, необходимо авторизоваться.</p>
                    <button type="button" class="button primary" data-help-auth>Авторизоваться</button>
                </div>
                <form data-help-form hidden>
                    <span class="select-control app-context-help__topic-control">
                    <select name="topic" required aria-label="Тема вопроса">
                        <option value="">Выберите тему</option>
                        @foreach (config('support.question_topics', []) as $topic => $label)
                            <option value="{{ $topic }}">{{ $label }}</option>
                        @endforeach
                    </select>
                        <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"
                             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m6 9 6 6 6-6" />
                        </svg>
                    </span>
                    <textarea name="message" rows="5" required minlength="10" maxlength="5000"
                              aria-label="Ваш вопрос" placeholder="Опишите ситуацию и что нужно уточнить"></textarea>
                    <p class="app-context-help__ask-hint">Вопрос создаст обращение в разделе «Сообщения». После отправки вы перейдёте в переписку с техподдержкой.</p>
                    <p role="status" aria-live="polite" data-help-form-status></p>
                    <button type="submit" class="button primary">Отправить вопрос</button>
                </form>
                @if($publicEmail = app(\App\Modules\Portal\Application\Services\PortalContactSettings::class)->publicEmail())
                    <p class="app-context-help__mail">Также можно написать на <a href="{{ $publicEmail['href'] }}">{{ $publicEmail['value'] }}</a>.</p>
                @endif
            </div>
        </details>
    </div>
</dialog>
