{{-- Preview fixture: use the actual production discussion, composer and modal classes. No application data or endpoints. --}}
@php
    $isAppTheme = true;
    $primaryButton = 'button primary';
    $secondaryButton = 'button secondary';
    $chatEvent = (object) ['id' => 'studio'];
@endphp
@include('components.event-discussion-styles')
@include('ui-studio.cases.unified-chat-candidate')
<main id="studio-event-page" class="container" style="padding-block:32px">
    <button type="button" class="button secondary" data-studio-open>Мероприятие</button>
</main>
<dialog class="mskba-modal venue-play-dialog venue-play-dialog--app" data-studio-chat aria-labelledby="studio-chat-title">
    <header class="mskba-modal__header venue-play-dialog__header">
        <h2 id="studio-chat-title">Мероприятие</h2>
        <button type="button" class="icon-button venue-play-dialog__messages-jump" data-studio-messages-jump aria-label="Перейти к сообщениям" title="К сообщениям"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 9h8M8 13h5M5 19l1-4a8 8 0 1 1 4 4z"/></svg></button>
        <button type="button" class="icon-button venue-play-dialog__close" data-studio-close aria-label="Закрыть" autofocus><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12M6 6l12 12"/></svg></button>
    </header>
    <div class="mskba-modal__body venue-play-dialog__body mskba-scroll">
        <article class="venue-play-dialog__detail">
            <div class="venue-play-dialog__summary">
                <h3>Игра на м. Яхромская</h3>
                <p class="venue-play-dialog__event-meta">Игра · 22.11.2026 12:00 · время предварительное</p>
                <p><strong>Описание:</strong> Хуперы любого уровня, главное желание</p>
            </div>
            @include('components.event-discussion', ['chatCanRespond' => true])
        </article>
    </div>
    <footer class="mskba-modal__footer venue-play-dialog__footer" data-venue-play-detail-footer>
        <button class="button secondary" type="button" data-studio-close>Назад</button>
        <a class="button primary" target="_top" href="{{ route('ui-studio.show', ['ui_name'=>'event-detail','theme'=>$theme,'width'=>$width,'state'=>$state === 'disabled' ? 'guest' : 'confirmed'], false) }}">К мероприятию</a>
    </footer>
</dialog>
@include('components.event-discussion-delete-dialog')
@include('ui-studio.cases.unified-chat-fixture')
