@php
    $headerUser = auth()->user();
    $profile = $headerUser?->profile;
    $telegramAccount = $headerTelegramAccount ?? null;
    $displayName = trim((string) ($profile?->first_name ?: $telegramAccount?->first_name ?: $headerUser?->username));
    $initials = $displayName !== '' ? mb_strtoupper(mb_substr($displayName, 0, 2)) : '';
    $avatarUrl = $profile?->getAttribute('avatar_url') ?: $telegramAccount?->photo_url;
    $profileLabel = $headerUser ? 'Открыть профиль' : 'Войти или зарегистрироваться';
@endphp

<div class="header-cell header-profile-wrapper partial-wrapper partial-header-profile">
    @if($headerUser || ($isTelegramMiniApp ?? false))
        <a
            href="{{ route('account') }}"
            class="site-profile-trigger"
            aria-label="{{ $profileLabel }}"
            data-mobile-profile
            data-authenticated="{{ $headerUser ? '1' : '0' }}"
        >
            <i class="ti ti-user site-profile-trigger__guest" aria-hidden="true" @if($headerUser) hidden @endif data-profile-guest></i>
            <img
                src="{{ $avatarUrl ?: '' }}"
                alt=""
                class="site-profile-trigger__avatar"
                @if(! $avatarUrl) hidden @endif
                data-profile-avatar
            >
            <span class="site-profile-trigger__initials" @if($avatarUrl || ! $headerUser) hidden @endif data-profile-initials>{{ $initials }}</span>
        </a>
    @else
        <button
            type="button"
            class="site-profile-trigger js-handler"
            aria-label="{{ $profileLabel }}"
            data-handler="modal"
            data-modal-action="open"
            data-modal-target="auth-entry-classic"
            data-mobile-profile
            data-authenticated="0"
        >
            <i class="ti ti-user site-profile-trigger__guest" aria-hidden="true" data-profile-guest></i>
            <img src="" alt="" class="site-profile-trigger__avatar" hidden data-profile-avatar>
            <span class="site-profile-trigger__initials" hidden data-profile-initials></span>
        </button>
    @endif
</div>
