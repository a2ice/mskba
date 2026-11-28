<dialog class="mskba-modal app-contact-wizard__dialog" data-community-contact-dialog aria-labelledby="team-contact-title">
    <header class="mskba-modal__header app-contact-wizard__header">
        <h2 id="team-contact-title">Связаться с командой</h2>
        <button type="button" class="icon-button" data-community-contact-close aria-label="Закрыть окно"><svg aria-hidden="true"><use href="#close"/></svg></button>
    </header>
    <div class="mskba-modal__body app-contact-wizard__body app-community-contact-options">
        <p>Выбери, кому написать сообщение.</p>
        @foreach($contactPeople as $contact)
            <button type="button" class="button secondary app-community-contact-option" data-direct-message-open data-direct-return-focus="team-contact-trigger" data-recipient-id="{{ $contact['person']['recipient_id'] }}" data-recipient-name="{{ $contact['person']['name'] }}">
                <span>{{ $contact['person']['name'] }} · {{ $contact['label'] }}</span>
            </button>
        @endforeach
    </div>
</dialog>
