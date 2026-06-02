@php
    $user = !empty($user) ? $user : auth()->user();
    $displayName = '';
    if($user) {
        if($user?->profile?->first_name) {
            $displayName = trim($user->profile->first_name . ($user->profile?->last_name ? ' ' . $user->profile->last_name : ''));
        } else {
            $displayName = $user->username;
        }
    }

    $gender = $user?->profile?->gender;

    $placeholderUrl = match ($gender) {
        \App\Modules\Identity\Domain\Enums\UserGenderEnum::FEMALE => asset('images/blank/avatar/avatar-female.png'),
        \App\Modules\Identity\Domain\Enums\UserGenderEnum::MALE => asset('images/blank/avatar/avatar-male.png'),
        default => asset('images/blank/avatar/avatar-male.png'),
    };

    $avatarUrl = $user?->profile?->avatar_url ?? $placeholderUrl;
@endphp

<div class="partial-wrapper partial-avatar text-center">
    <div class="avatar-wrapper mb-3">
        @if ($avatarUrl)
            <img src="{{ $avatarUrl }}" alt="Аватар" class="rounded-circle avatar-lg">
        @else
            <div class="avatar-placeholder rounded-circle avatar-lg d-flex align-items-center justify-content-center"></div>
        @endif
    </div>
    <h5 class="card-title">{{ $displayName }}</h5>
    <p class="card-text">{{ $user?->contacts?->primary_email }}</p>
</div>
