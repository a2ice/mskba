{{-- Only accept an already privacy-filtered URL. Never inspect a User model here. --}}
<img class="{{ $avatarClass ?? 'app-participant-card__avatar' }}"
     src="{{ $avatarUrl ?: ($avatarPlaceholderUrl ?? asset('images/blank/avatar/avatar-male.png')) }}"
     alt="" loading="lazy"
     @if(empty($avatarUrl) && !empty($avatarRestricted)) title="Отображение аватара запрещено в настройках профиля" @endif>
