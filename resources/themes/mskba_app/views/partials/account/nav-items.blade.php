@foreach ($accountItems as $item)
    @continue(! ($item['visible'] ?? true))
    @if ($item['divider'] ?? false)
        <div class="app-account-nav__divider" role="separator"></div>
    @endif

    @if (! empty($item['children']))
        <details class="app-account-nav__group{{ ($item['groupType'] ?? null) === 'participation-role' ? ' app-account-nav__group--role' : '' }}"
                 @if (isset($item['role'])) data-account-role-group="{{ $item['role'] }}" @endif
                 @if ($item['active']) open @endif>
            <summary class="app-account-nav__link app-account-nav__group-trigger{{ $item['active'] ? ' is-active' : '' }}">
                <span class="app-account-nav__label">{{ $item['label'] }}</span>
                <svg aria-hidden="true"><use href="#chevron-down"/></svg>
            </summary>
            <div class="app-account-nav__children">
                @foreach ($item['children'] as $child)
                    @continue(! ($child['visible'] ?? true))
                    <a class="app-account-nav__link app-account-nav__child{{ $child['active'] ? ' is-active' : '' }}"
                       href="{{ $child['url'] }}"
                       @if ($child['active']) aria-current="page" @endif>
                        <span class="app-account-nav__label">{{ $child['label'] }}</span>
                        @if (($child['badge'] ?? 0) > 0)
                            <span class="badge app-account-nav__badge"
                                  @if (! empty($child['badgeAttribute'])) {{ $child['badgeAttribute'] }} @else data-notification-count @endif>
                                {{ $child['badge'] > 99 ? '99+' : $child['badge'] }}
                            </span>
                        @endif
                    </a>
                @endforeach
            </div>
        </details>
    @else
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
    @endif
@endforeach
