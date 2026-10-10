{{-- Shared MSKBA App avatar partial: pass a user and optional class for any placement. --}}
@php
    $profile = $user?->profile;
    $avatarUrl = $profile?->avatarUrl();
    $name = app(\App\Presentation\Identity\UserAddressing::class)->greetingName($user);
    $initials = mb_strtoupper(mb_substr($name, 0, 2));
    $role = $user?->system_role;
    $roleBadge = $role?->avatarBadge();
@endphp
<span class="avatar app-user-avatar {{ $class ?? '' }}">
    <span class="app-user-avatar__visual">
        @if ($avatarUrl)
            <img class="app-user-avatar__image" src="{{ $avatarUrl }}" alt="">
        @else
            {{ $initials }}
        @endif
    </span>
    @if ($roleBadge)
        <span class="app-user-avatar__role"
              data-avatar-role="{{ $role->value }}"
              title="{{ $role->label() }}"
              style="--app-avatar-role-color: {{ $roleBadge['color'] }}"
              aria-hidden="true">{{ $roleBadge['initial'] }}</span>
    @endif
</span>
