<div class="venue-catalog-payment-row">
    <div class="venue-catalog-payment-row__badges">
        @include('venues.partials.payment-badge', ['paymentStates' => $paymentStates, 'legacyPayment' => $legacyPayment ?? false])
    </div>
    @if($courtCount > 1)
        <span class="venue-catalog-payment-row__count">Игр. зон: {{ $courtCount }}</span>
    @endif
</div>
