@foreach ($accountItems as $item)
    @continue(! ($item['visible'] ?? true))
    @if ($item['divider'] ?? false)
        <div class="app-account-nav__divider" role="separator"></div>
    @endif
    <a class="app-account-nav__link{{ $item['active'] ? ' is-active' : '' }}"
       href="{{ $item['url'] }}"
       @if ($item['active']) aria-current="page" @endif>
        <span class="app-account-nav__label">{{ $item['label'] }}</span>
        @if (($item['badge'] ?? 0) > 0)
            <span class="badge app-account-nav__badge"
                  @if (! empty($item['badgeAttribute'])) {{ $item['badgeAttribute'] }} @else data-notification-count @endif>
                {{ $item['badge'] > 99 ? '99+' : $item['badge'] }}
            </span>
        @endif
    </a>
@endforeach
