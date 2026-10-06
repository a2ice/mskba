@php
    $requestPath = '/'.ltrim(request()->path(), '/');
    $sections = config('context-submenu.sections', []);

    $exactSection = collect($sections)->first(function (array $section) use ($requestPath): bool {
        return in_array($requestPath, $section['exact'] ?? [], true);
    });

    $contextSubmenu = $exactSection ?? collect($sections)->first(function (array $section) use ($requestPath): bool {
        $pattern = $section['pattern'] ?? null;

        return is_string($pattern) && @preg_match($pattern, $requestPath) === 1;
    });

    $contextSubmenuItems = $contextSubmenu['items'] ?? [];

    $renderContextSubmenuItems = function (array $items, int $level = 0) use (&$renderContextSubmenuItems): string {
        return collect($items)->map(function (array $item) use (&$renderContextSubmenuItems, $level): string {
            $label = e((string) ($item['label'] ?? ''));
            $url = e((string) ($item['url'] ?? '#'));
            $children = array_values($item['children'] ?? []);
            $hasChildren = $children !== [];

            if (! $hasChildren) {
                return '<a class="context-submenu__link" href="'.$url.'">'.$label.'</a>';
            }

            return '<div class="context-submenu__item context-submenu__item--dropdown">'
                .'<a class="context-submenu__link context-submenu__toggle" href="'.$url.'" aria-haspopup="true">'.$label.'</a>'
                .'<div class="context-submenu__dropdown context-submenu__dropdown--level-'.$level.'">'
                .$renderContextSubmenuItems($children, $level + 1)
                .'</div></div>';
        })->implode('');
    };
@endphp

@if ($contextSubmenuItems !== [])
    <div class="context-submenu" data-context-submenu>
        <nav class="inner context-submenu__inner" aria-label="Навигация раздела">
            {!! $renderContextSubmenuItems($contextSubmenuItems) !!}
        </nav>
    </div>
@endif
