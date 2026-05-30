@php
    $user = isset($user) ? $user : auth()->user();
@endphp

<div class="partial-wrapper partial-avatar text-center">
    <div class="avatar-wrapper mb-3">
        @if ($user?->profile?->avatar_url)
            <img src="{{ $user?->profile?->avatar_url }}" alt="Аватар" class="rounded-circle avatar-lg">
        @else
            <div class="avatar-placeholder rounded-circle avatar-lg d-flex align-items-center justify-content-center"></div>
        @endif
    </div>
    <h5 class="card-title">{{ $user?->profile?->first_name }} {{ $user?->profile?->last_name }}</h5>
    <p class="card-text">{{ $user?->contacts?->primary_email }}</p>
</div>