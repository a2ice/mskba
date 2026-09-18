@php
    $authSocialProviders = array_values(array_filter([
        [
            'enabled' => ltrim(trim((string) config('telegram.bot_username')), '@') !== '',
            'view' => 'theme::partials.auth.telegram-login',
        ],
        [
            'enabled' => trim((string) config('vk.app_id')) !== '',
            'view' => 'theme::partials.auth.vk-login',
        ],
    ], static fn (array $provider): bool => $provider['enabled']));
@endphp

@if($authSocialProviders !== [])
    <div class="auth-social-login">
        @foreach(array_chunk($authSocialProviders, 2) as $groupIndex => $providerGroup)
            <div class="auth-social-login__separator" aria-hidden="true">
                <span>{{ $groupIndex === 0 ? 'или быстрый вход через' : 'или' }}</span>
            </div>

            <div class="auth-social-login__row">
                @foreach($providerGroup as $provider)
                    @include($provider['view'], ['authSocialGrid' => true])
                @endforeach
            </div>
        @endforeach
    </div>
@endif
