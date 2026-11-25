@php
    $knownStates = array_values(array_intersect(['free', 'paid'], $paymentStates));
    $hasUnknown = in_array('unknown', $paymentStates, true);
    $legacyPayment = $legacyPayment ?? false;
@endphp
@if($knownStates !== [])
    <span class="venue-payment-badge">
        @foreach($knownStates as $state)
            <span @class(['badge', $legacyPayment ? ($state === 'free' ? 'badge--success' : 'badge--warning') : ($state === 'free' ? 'success' : 'warning')])>{{ $state === 'free' ? 'Бесплатно' : 'Платно' }}</span>
        @endforeach
    </span>
@endif
@if($hasUnknown)
    <span @class(['badge', 'badge--default' => $legacyPayment])>Условия уточняются</span>
@elseif($knownStates === [])
    <span @class(['badge', 'badge--default' => $legacyPayment])>Нет доступных залов</span>
@endif
