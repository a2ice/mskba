{{-- Shared MSKBA App avatar partial: pass a user and optional class for any placement. --}}
@php
    $profile = $user?->profile;
    $avatarUrl = $profile?->avatarUrl();
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
            <svg class="app-user-avatar__placeholder" viewBox="0 0 24 24"
                 stroke="currentColor" stroke-width="2" fill="none"
                 stroke-linecap="round" stroke-linejoin="round"
                 aria-hidden="true" focusable="false"><use href="#user"/></svg>
        @endif
    </span>
    @if ($showNotifications ?? false)
        <span class="app-user-avatar__unread" data-notification-count
              data-unread-count="{{ $notificationCount }}"
              @if ($notificationCount === 0) hidden @endif
              aria-label="Непрочитанных уведомлений: {{ $notificationCount }}">{{ $notificationCount > 9 ? '∞' : $notificationCount }}</span>
    @endif
</span>
