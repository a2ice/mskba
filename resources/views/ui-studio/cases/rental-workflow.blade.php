{{-- UI Studio only. Real account message classes and actions trigger; all state is local. --}}
@include('ui-studio.cases.rental-workflow-styles')
<main class="container rental-studio" data-rental-studio data-fixture="{{ $state }}">
    <section class="panel rs-controls" aria-label="Управление демонстрацией">
        <h1>Аренда · UI Studio</h1>
        <div class="rs-control-row">
            <label class="check-label"><input type="checkbox" data-rs-agent checked> Агент</label>
            <label class="check-label"><input type="checkbox" data-rs-venue checked> Представитель площадки</label>
            <label>Начальный сценарий
                <select data-rs-mode><option value="booking">Заявка на аренду</option><option value="inquiry">Уточнить аренду</option></select>
            </label>
            <button type="button" class="button secondary" data-rs-reset>Начать заново</button>
        </div>
        <div class="rs-role-row" data-rs-roles aria-label="Переключение роли"></div>
        <div class="rs-control-row">
            <button type="button" class="button secondary" data-rs-race>Симулировать конфликт</button>
            <button type="button" class="button secondary" data-rs-expire>Истечение холда</button>
            <button type="button" class="button secondary" data-rs-mail>Email</button>
        </div>
        <p data-rs-clock class="rs-muted"></p>
    </section>
    <div class="app-message-workspace rs-workspace">
        <aside class="panel app-messages__rail rs-rail" aria-label="Сообщения">
            <header class="app-messages__rail-header"><strong>Сообщения</strong><span>Заявки и обращения</span></header>
            <nav class="app-messages__rail-list" data-rs-rail aria-label="Диалоги по аренде"></nav>
        </aside>
        <section class="app-messages app-messages--thread rs-thread" aria-label="Переписка по аренде">
            <header class="panel app-messages__thread-header">
                <span class="app-messages__avatar" aria-hidden="true"><svg><use href="#message"/></svg></span>
                <div class="app-messages__thread-contact"><div><h2 data-rs-title></h2><p data-rs-subtitle></p></div></div>
            </header>
            <div class="panel app-messages__chat-log rs-log" tabindex="0" role="region" aria-label="История переписки">
                <div class="app-messages__history" data-rs-history role="log" aria-live="polite"></div>
            </div>
            <div class="panel app-messages__compose">
                <textarea data-rs-text maxlength="4000" rows="2" placeholder="Введите сообщение…" aria-label="Сообщение по заявке"></textarea>
                <div class="app-messages__compose-footer">
                    <div class="app-messages__rental-actions">
                        <details class="app-context-actions app-messages__actions">
                            {!! \Illuminate\Support\Facades\View::file(resource_path('themes/mskba_app/views/pages/account/partials/chat-actions-trigger.blade.php'), ['ariaLabel' => 'Действия по аренде'])->render() !!}
                            <div class="app-context-actions__menu" data-rs-actions aria-label="Действия по аренде"></div>
                        </details>
                    </div>
                    <button class="button primary" type="button" data-rs-send disabled>Отправить</button>
                </div>
            </div>
        </section>
    </div>
    <dialog class="mskba-modal rs-modal" data-rs-dialog aria-labelledby="rs-dialog-title">
        <header class="mskba-modal__header"><h2 id="rs-dialog-title" data-rs-dialog-title></h2><button class="icon-button" type="button" data-rs-close aria-label="Закрыть">×</button></header>
        <div class="mskba-modal__body mskba-scroll" data-rs-dialog-body></div>
        <footer class="mskba-modal__footer" data-rs-dialog-footer></footer>
    </dialog>
</main>
@include('ui-studio.cases.rental-workflow-fixture')
