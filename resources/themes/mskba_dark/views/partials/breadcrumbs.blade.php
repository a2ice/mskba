@php
    $title = $title ?? 'Страница';
    $routeName = request()->route()?->getName();
    $routes = app('router')->getRoutes();

    $items = $breadcrumbs ?? null;

    if ($items === null) {
        $items = [];
        $routeNames = [];

        if ($routeName) {
            $segments = explode('.', $routeName);

            foreach (array_keys($segments) as $index) {
                $candidate = implode('.', array_slice($segments, 0, $index + 1));
                $sectionIndex = $candidate.'.index';
                $name = match (true) {
                    $candidate === $routeName && Route::has($candidate) => $candidate,
                    Route::has($candidate) => $candidate,
                    Route::has($sectionIndex) => $sectionIndex,
                    default => null,
                };

                if ($name && ! in_array($name, $routeNames, true)) {
                    $routeNames[] = $name;
                }
            }
        }

        foreach ($routeNames as $name) {
            $route = $routes->getByName($name);
            $items[] = [
                'label' => $route?->defaults['breadcrumb'] ?? ($name === $routeName ? $title : \Illuminate\Support\Str::headline(\Illuminate\Support\Str::afterLast($name, '.'))),
                'url' => $name === $routeName ? null : route($name),
            ];
        }

        if ($items === []) {
            $items[] = ['label' => $title];
        }
    }

    $items = $items instanceof \Illuminate\Support\Collection ? $items->all() : $items;
    $trail = array_merge(
        [['label' => 'Главная', 'url' => route('home')]],
        $items
    );
@endphp

@if (!request()->routeIs('home'))
    <nav class="page-breadcrumbs" aria-label="Навигационная цепочка">
        <ol class="page-breadcrumbs__list">
            @foreach ($trail as $item)
                @php
                    $label = $item['label'] ?? '';
                    $url = $item['url'] ?? null;
                    $isCurrent = $loop->last;
                @endphp

                <li class="page-breadcrumbs__item">
                    @if ($url && ! $isCurrent)
                        <a class="page-breadcrumbs__link" href="{{ $url }}">{{ $label }}</a>
                    @elseif ($isCurrent)
                        <span class="page-breadcrumbs__current" aria-current="page">{{ $label }}</span>
                    @else
                        <span class="page-breadcrumbs__label">{{ $label }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
