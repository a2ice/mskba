@php
    $contextSubmenuItems = app(\App\Presentation\Navigation\ContextSubmenuResolver::class)->resolve();
    $trail = app(\App\Presentation\Breadcrumbs\BreadcrumbsResolver::class)->resolve($title ?? null, $breadcrumbs ?? null);
    $historyFallbackUrl = route('welcome');

    foreach (array_reverse(array_slice($trail, 0, -1)) as $parentItem) {
        if (! empty($parentItem['url'])) {
            $historyFallbackUrl = $parentItem['url'];
            break;
        }
    }

    $renderContextSubmenuItems = function (array $items, int $level = 0) use (&$renderContextSubmenuItems): string {
        return collect($items)->map(function (array $item) use (&$renderContextSubmenuItems, $level): string {
            if (($item['visible'] ?? true) !== true) {
                return '';
            }

            $label = e((string) ($item['label'] ?? ''));
            $url = e((string) ($item['url'] ?? '#'));
            $children = array_values(array_filter(
                $item['children'] ?? [],
                static fn (array $child): bool => ($child['visible'] ?? true) === true,
            ));

            if ($children === []) {
                return '<a class="context-submenu__dropdown-link" href="'.$url.'" role="menuitem">'.$label.'</a>';
            }

            return '<div class="context-submenu__dropdown-item context-submenu__dropdown-item--nested">'
                .'<a class="context-submenu__dropdown-link context-submenu__dropdown-link--toggle" href="'.$url.'" role="menuitem">'.$label.'</a>'
                .'<div class="context-submenu__nested context-submenu__nested--level-'.$level.'" role="menu">'
                .$renderContextSubmenuItems($children, $level + 1)
                .'</div></div>';
        })->implode('');
    };
@endphp

<div class="context-submenu" data-context-submenu>
    <div class="inner context-submenu__inner">
        <div class="context-submenu__breadcrumbs">
            @include('theme::partials.breadcrumbs', [
                'contextBar' => true,
                'showBack' => false,
            ])
        </div>

        <div class="context-submenu__actions">
            <button
                class="context-submenu__actions-toggle"
                type="button"
                aria-haspopup="true"
                aria-expanded="false"
            >
                Действия
            </button>

            <div class="context-submenu__dropdown" role="menu">
                @if ($contextSubmenuItems !== [])
                    <div class="context-submenu__group context-submenu__group--section" aria-label="Действия раздела">
                        {!! $renderContextSubmenuItems($contextSubmenuItems) !!}
                    </div>
                    <span class="context-submenu__divider" aria-hidden="true"></span>
                @endif

                <div class="context-submenu__group context-submenu__group--system" aria-label="Системные действия">
                    <button
                        type="button"
                        class="context-submenu__dropdown-link context-submenu__dropdown-button js-handler"
                        data-handler="historyBack"
                        data-history-fallback="{{ $historyFallbackUrl }}"
                        role="menuitem"
                    >
                        Назад
                    </button>

                    <button
                        type="button"
                        class="context-submenu__dropdown-link context-submenu__dropdown-button"
                        data-context-share
                        role="menuitem"
                    >
                        Поделиться
                    </button>

                    <a
                        class="context-submenu__dropdown-link"
                        href="{{ url('/faq') }}"
                        role="menuitem"
                    >
                        Помощь
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
