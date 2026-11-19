@if($invoiceCard)
<section class="app-rental-invoice-card" data-rental-invoice-card>
    <strong>{{ $invoiceCard['title'] }} · {{ $invoiceCard['number'] }}</strong>
    <div class="app-rental-invoice-card__amount">{{ $invoiceCard['amount'] }} {{ $invoiceCard['currency'] }}</div>
    <p>{{ $invoiceCard['status_label'] }} · До {{ $invoiceCard['due_at'] }}</p>
    <p>Способ оплаты: {{ implode(', ', $invoiceCard['methods']) }}</p>
    @if($invoiceCard['instructions'])<p>{{ $invoiceCard['instructions'] }}</p>@endif
    @if(!empty($invoiceCard['details']))
        @if(!empty($invoiceCard['details']['bank']))<p>Банк: {{ $invoiceCard['details']['bank'] }}</p>@endif
        @if(!empty($invoiceCard['details']['phone']))<p>Телефон: {{ $invoiceCard['details']['phone'] }}</p>@endif
        @if(!empty($invoiceCard['details']['card']))<p>Номер карты: {{ $invoiceCard['details']['card'] }}</p>@endif
        @if(!empty($invoiceCard['details']['purpose']))<p>Комментарий к переводу: {{ $invoiceCard['details']['purpose'] }}</p>@endif
    @endif
    @if($invoiceCard['reference'])<p>Номер операции: {{ $invoiceCard['reference'] }}</p>@endif
    @if($invoiceCard['claim_note'])<p>Комментарий плательщика: {{ $invoiceCard['claim_note'] }}</p>@endif
    <div class="app-rental-invoice-card__actions">
        @if($invoiceCard['copy_text'])
            <button class="button secondary" type="button" data-rental-invoice-copy="{{ $invoiceCard['copy_text'] }}">Скопировать реквизиты</button>
        @endif
        @if($invoiceCard['claim_url'])
            <button class="button primary" type="button" data-rental-invoice-claim
                data-url="{{ $invoiceCard['claim_url'] }}"
                data-reference-required="{{ $invoiceCard['reference_required'] ? '1' : '0' }}"
                data-confirmation-hint="{{ $invoiceCard['confirmation_hint'] }}">Я оплатил</button>
        @endif
        @if($invoiceCard['settle_url'])
            <button class="button primary btn btn--primary btn--sm" type="button" data-rental-invoice-settle
                data-url="{{ $invoiceCard['settle_url'] }}"
                data-method="{{ $invoiceCard['settle_method'] }}">Подтвердить получение</button>
        @endif
        <a class="button secondary" href="{{ $invoiceCard['booking_url'] }}">Открыть счёт</a>
    </div>
</section>
@endif
