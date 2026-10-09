@foreach ($items as $action)
    @continue(! ($action['visible'] ?? true))
    @php
        $children = array_values(array_filter(
            $action['children'] ?? [],
            static fn (array $child): bool => ($child['visible'] ?? true) === true,
        ));
    @endphp
    @if ($children !== [])
        <details class="app-context-actions__nested">
            <summary>{{ $action['label'] }}</summary>
            <div class="app-context-actions__nested-links">
                @if (! empty($action['url']))
                    <a href="{{ $action['url'] }}">{{ $action['label'] }}</a>
                @endif
                @include('theme::partials.context-actions-items', ['items' => $children])
            </div>
        </details>
    @elseif (! empty($action['url']))
        <a href="{{ $action['url'] }}">{{ $action['label'] }}</a>
    @endif
@endforeach
