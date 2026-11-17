@php
    $accountNavDepth = $accountNavDepth ?? 0;
@endphp
@foreach ($accountItems as $item)
    @continue(! ($item['visible'] ?? true))
    @if ($item['divider'] ?? false)
        <div class="app-account-nav__divider" role="separator"></div>
    @endif

    @if (! empty($item['children']))
        <details class="app-account-nav__group{{ $accountNavDepth > 0 ? ' app-account-nav__group--nested' : '' }}"
                 @if ($item['active']) open @endif>
            <summary class="app-account-nav__link app-account-nav__group-trigger{{ $accountNavDepth > 0 ? ' app-account-nav__child' : '' }}{{ $item['active'] ? ' is-active' : '' }}"
                     title="{{ $item['label'] }}">
                @if (! empty($item['icon']))
                    <svg class="app-account-nav__icon" aria-hidden="true"><use href="#{{ $item['icon'] }}"/></svg>
                @endif
                <span class="app-account-nav__label">{{ $item['label'] }}</span>
                <svg aria-hidden="true"><use href="#chevron-down"/></svg>
            </summary>
            <div class="app-account-nav__children">
                @include('theme::partials.account.nav-items', [
                    'accountItems' => $item['children'],
                    'accountNavDepth' => $accountNavDepth + 1,
                ])
            </div>
        </details>
    @else
        <a class="app-account-nav__link{{ $accountNavDepth > 0 ? ' app-account-nav__child' : '' }}{{ $item['active'] ? ' is-active' : '' }}"
           href="{{ $item['url'] }}" title="{{ $item['label'] }}"
           @if ($item['active']) aria-current="page" @endif>
            @if (! empty($item['icon']))
                <svg class="app-account-nav__icon" aria-hidden="true"><use href="#{{ $item['icon'] }}"/></svg>
            @endif
            <span class="app-account-nav__label">{{ $item['label'] }}</span>
            @if (($item['badge'] ?? 0) > 0 || ($item['notificationCounter'] ?? false))
                <span class="badge app-account-nav__badge"
                      @if (! empty($item['badgeAttribute'])) {{ $item['badgeAttribute'] }} @else data-notification-count @endif
                      @if (($item['badge'] ?? 0) < 1) hidden @endif>
                    {{ ($item['badge'] ?? 0) > 99 ? '99+' : ($item['badge'] ?? 0) }}
                </span>
            @endif
        </a>
    @endif
@endforeach
