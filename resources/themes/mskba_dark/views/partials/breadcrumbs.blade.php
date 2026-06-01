@php
    $trail = app(\App\Presentation\Breadcrumbs\BreadcrumbsResolver::class)->resolve($title ?? null);
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
