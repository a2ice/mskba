@php
    $statusIcon = match ($venue->statusSlug) {
        'confirmed' => 'ti-circle-check',
        'blocked' => 'ti-lock',
        default => 'ti-clock',
    };
@endphp

<span class="account-venue-status account-venue-status--{{ $venue->statusSlug }}">
    <i class="ti {{ $statusIcon }}" aria-hidden="true"></i>
    {{ $venue->status }}
</span>
