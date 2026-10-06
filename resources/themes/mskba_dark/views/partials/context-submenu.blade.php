@php
    $contextSubmenuItems = app(\App\Presentation\Navigation\ContextSubmenuResolver::class)->resolve();

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
                return '<a class="context-submenu__dropdown-link" href="'.$url.'">'.$label.'</a>';
            }

            return '<div class="context-submenu__dropdown-item context-submenu__dropdown-item--nested">'
                .'<a class="context-submenu__dropdown-link context-submenu__dropdown-link--toggle" href="'.$url.'">'.$label.'</a>'
                .'<div class="context-submenu__nested context-submenu__nested--level-'.$level.'">'
                .$renderContextSubmenuItems($children, $level + 1)
                .'</div></div>';
        })->implode('');
    };
@endphp

@if ($contextSubmenuItems !== [])
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
                    {!! $renderContextSubmenuItems($contextSubmenuItems) !!}
                </div>
            </div>
        </div>
    </div>
@endif
