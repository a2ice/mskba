@php
    $trail = app(\App\Presentation\Breadcrumbs\BreadcrumbsResolver::class)->resolve($title ?? null, $breadcrumbs ?? null);
@endphp

@if (!request()->routeIs('home'))
    <nav class="page-breadcrumbs" aria-label="Навигационная цепочка">
        <ol class="page-breadcrumbs__list">
            @foreach ($trail as $item)
                @php
                    $label = $item['label'] ?? '';
                    $isLabelTruncated = mb_strlen($label) > 13;
                    $displayLabel = $isLabelTruncated ? mb_substr($label, 0, 10).'...' : $label;
                    $url = $item['url'] ?? null;
                    $isCurrent = $loop->last;
                @endphp

                <li class="page-breadcrumbs__item">
                    @if ($url && ! $isCurrent)
                        <a
                            class="page-breadcrumbs__link"
                            href="{{ $url }}"
                            @if($isLabelTruncated) title="{{ $label }}" data-tooltip-variant="title" @endif
                        >{{ $displayLabel }}</a>
                    @elseif ($isCurrent)
                        <span
                            class="page-breadcrumbs__current"
                            aria-current="page"
                            @if($isLabelTruncated) title="{{ $label }}" data-tooltip-variant="title" @endif
                        >{{ $displayLabel }}</span>
                    @else
                        <span
                            class="page-breadcrumbs__label"
                            @if($isLabelTruncated) title="{{ $label }}" data-tooltip-variant="title" @endif
                        >{{ $displayLabel }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
        <button
            type="button"
            class="page-breadcrumbs__back js-handler"
            data-handler="historyBack"
            aria-label="Вернуться на предыдущую страницу"
        >
            Назад
        </button>
    </nav>
@endif
