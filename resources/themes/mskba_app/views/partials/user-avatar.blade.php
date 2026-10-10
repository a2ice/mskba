{{-- Shared MSKBA App avatar partial: pass a user and optional class for any placement. --}}
@php
    $profile = $user?->profile;
    $avatarUrl = $profile?->avatarUrl();
    $name = app(\App\Presentation\Identity\UserAddressing::class)->greetingName($user);
    $initials = mb_strtoupper(mb_substr($name, 0, 2));
    $role = $user?->system_role;
    $roleColor = $role?->avatarBorderColor();
    $notificationCount = max(0, (int) ($unreadCount ?? 0));
@endphp
<span class="avatar app-user-avatar {{ $class ?? '' }}"
      @if ($roleColor) data-avatar-role="{{ $role->value }}"
          title="{{ $role->label() }}"
          style="--app-avatar-role-color: {{ $roleColor }}" @endif>
    <span class="app-user-avatar__visual">
        @if ($avatarUrl)
            <img class="app-user-avatar__image" src="{{ $avatarUrl }}" alt="">
        @else
            {{ $initials }}
        @endif
    </span>
    @if ($showNotifications ?? false)
        <span class="app-user-avatar__unread" data-notification-count
              @if ($notificationCount === 0) hidden @endif
              aria-label="Непрочитанных уведомлений: {{ $notificationCount }}">{{ $notificationCount > 99 ? '99+' : $notificationCount }}</span>
    @endif
</span>
