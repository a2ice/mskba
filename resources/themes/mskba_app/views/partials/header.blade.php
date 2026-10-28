@php
    $items = app(\App\Presentation\Navigation\MenuResolver::class)->resolve('main');
    $headerUser = auth()->user();
    $headerNeedsPrivacySetup = $headerUser !== null
        && app(\App\Modules\Identity\Application\Services\PersonalDataDistributionConsentService::class)->requiresSetup($headerUser);
    $displayName = $headerUser?->username ?: '';
    $headerUnreadCount = $headerUser
        ? app(\App\Modules\Notification\Application\UseCases\CountNewUserNotificationsHandler::class)->handle($headerUser)
        : 0;
    $headerRoleColor = $headerUser?->system_role?->avatarBorderColor();
    $headerFavoriteCount = $headerUser
        ? app(\App\Modules\Favorites\Application\Services\FavoriteCountService::class)->forUser($headerUser)
        : 0;
@endphp

<a class="skip" href="#mskba-app">К содержимому</a>
<header class="site-header app-site-header" data-app-header
    @auth data-notification-user-id="{{ $headerUser->canonical()->id }}"
          data-notification-sync-url="{{ route('account.notifications.new') }}" @endauth>
    <div class="container header-inner app-header-inner">
        <a class="brand app-header-brand" href="{{ route('welcome') }}" aria-label="MSKBA — главная" title="{{ config('app.name') }}">
            <span class="app-header-mark" aria-hidden="true"></span>
            <span>MSK<span class="accent">BA</span></span>
        </a>

        <nav class="desktop-nav app-header-nav" aria-label="Основная навигация">
            @foreach ($items as $item)
                @continue(! $item['visible'])
                @php
                    $children = array_values(array_filter($item['children'] ?? [], fn (array $child): bool => $child['visible'] ?? false));
                @endphp
                @if ($children !== [])
                    <details @class(['app-header-nav-group', 'is-active' => $item['active']])>
                        <summary class="app-header-nav-trigger">
                            {{ $item['label'] }}
                            <svg aria-hidden="true"><use href="#chevron-down"/></svg>
                        </summary>
                        <div class="app-header-dropdown">
                            @if ($item['url'])
                                <a href="{{ $item['url'] }}">Все {{ mb_strtolower($item['label']) }}</a>
                            @endif
                            @foreach ($children as $child)
                                @if (! empty($child['divider']))
                                    <span class="app-header-divider" aria-hidden="true"></span>
                                @endif
                                <a href="{{ $child['url'] }}" @if ($child['active']) aria-current="page" @endif>{{ $child['label'] }}</a>
                            @endforeach
                        </div>
                    </details>
                @elseif ($item['url'])
                    <a href="{{ $item['url'] }}" @if ($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
                @endif
            @endforeach
        </nav>

        <div class="header-actions app-header-actions">
            <a class="app-header-favorites" data-header-favorites
               href="{{ route('favorites.mine') }}"
               @auth
                   data-favorites-user-id="{{ $headerUser->canonical()->id }}"
                   data-favorites-sync-url="{{ route('favorites.count') }}"
               @else
                   data-auth-trigger data-auth-redirect="{{ route('favorites.mine', [], false) }}"
               @endauth
               aria-label="{{ $headerUser ? 'Избранное: '.$headerFavoriteCount : 'Избранное — войти в аккаунт' }}"
               title="{{ $headerUser ? 'Избранное: '.$headerFavoriteCount : 'Войти и открыть избранное' }}">
                <svg aria-hidden="true"><use href="#heart"/></svg>
                <span class="app-header-favorites__count" data-favorites-count
                      data-count="{{ $headerFavoriteCount }}"
                      @if ($headerFavoriteCount === 0) hidden @endif>{{ $headerFavoriteCount > 99 ? '99+' : $headerFavoriteCount }}</span>
            </a>
            @auth
                <a @class(['app-header-account', 'app-header-account--setup-pending' => $headerNeedsPrivacySetup])
                   href="{{ route('account') }}"
                   aria-label="Личный кабинет{{ $headerRoleColor ? ', роль: '.$headerUser->system_role->label() : '' }}"
                   @if ($headerNeedsPrivacySetup) title="Остался последний шаг регистрации" @endif>
                    <span class="app-header-account-label">{{ $displayName ?: 'Личный кабинет' }}</span>
                    @include('theme::partials.user-avatar', ['user' => $headerUser, 'class' => 'app-header-avatar', 'showNotifications' => true, 'unreadCount' => $headerUnreadCount])
                </a>
            @else
                <a class="button secondary app-header-login" href="{{ route('login') }}" data-auth-trigger>Войти</a>
            @endauth
        </div>

        <details class="app-header-mobile" data-app-mobile-nav>
            <summary class="icon-button app-header-mobile-toggle" aria-label="Главное меню">
                <span class="app-header-bars" aria-hidden="true"></span>
            </summary>
            <nav class="app-header-mobile-panel" aria-label="Основная мобильная навигация">
                @foreach ($items as $item)
                    @continue(! $item['visible'])
                    @php
                        $children = array_values(array_filter($item['children'] ?? [], fn (array $child): bool => $child['visible'] ?? false));
                    @endphp
                    @if ($children !== [])
                        <details class="app-header-mobile-group">
                            <summary @class(['app-header-mobile-link', 'is-active' => $item['active']])>
                                {{ $item['label'] }}
                                <svg aria-hidden="true"><use href="#chevron-down"/></svg>
                            </summary>
                            <div class="app-header-mobile-children">
                                @if ($item['url'])
                                    <a href="{{ $item['url'] }}">Все {{ mb_strtolower($item['label']) }}</a>
                                @endif
                                @foreach ($children as $child)
                                    <a href="{{ $child['url'] }}" @if ($child['active']) aria-current="page" @endif>{{ $child['label'] }}</a>
                                @endforeach
                            </div>
                        </details>
                    @elseif ($item['url'])
                        <a class="app-header-mobile-link" href="{{ $item['url'] }}" @if ($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
                    @endif
                @endforeach
                <div class="app-header-mobile-account">
                    @auth
                        <a href="{{ $headerNeedsPrivacySetup ? route('account.privacy.distribution') : route('account') }}"><svg aria-hidden="true"><use href="#user"/></svg> {{ $headerNeedsPrivacySetup ? 'Завершить регистрацию' : 'Личный кабинет' }}</a>
                        <a href="{{ route('account.notifications') }}"><svg aria-hidden="true"><use href="#message"/></svg> Сообщения</a>
                    @else
                        <a href="{{ route('login') }}" data-auth-trigger><svg aria-hidden="true"><use href="#user"/></svg> Войти в аккаунт</a>
                    @endauth
                </div>
            </nav>
        </details>
    </div>
</header>
