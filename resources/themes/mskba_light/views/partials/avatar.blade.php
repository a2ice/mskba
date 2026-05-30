@php
    $user = isset($user) ? $user : auth()->user();
@endphp

<div class="partial-wrapper partial-avatar text-center">
    <div class="avatar-wrapper mb-3">
        <img src="{{ $user?->profile?->avatar_url }}" alt="Аватар" class="rounded-circle avatar-lg">
    </div>
    <h5 class="card-title">{{ $user?->profile?->first_name }} {{ $user?->profile?->last_name }}</h5>
    <p class="card-text">{{ $user?->contacts?->primary_email }}</p>
</div>