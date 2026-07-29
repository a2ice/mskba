@php
    $score = (int) old($oldFieldName ?? $fieldName, $value ?? 5);
    $score = max(1, min(10, $score));
@endphp

<div class="account-player-profile__score" data-score-range>
    <input
        id="{{ $id }}"
        class="account-player-profile__range"
        type="range"
        name="{{ $fieldName }}"
        min="1"
        max="10"
        step="1"
        value="{{ $score }}"
        aria-valuemin="1"
        aria-valuemax="10"
        aria-valuenow="{{ $score }}"
        data-score-range-input
    >
    <output
        class="account-player-profile__score-value"
        for="{{ $id }}"
        aria-live="polite"
        data-score-range-value
    >{{ $score }}</output>
</div>
<div class="account-player-profile__score-scale" aria-hidden="true">
    <span>1</span>
    <span>10</span>
</div>
