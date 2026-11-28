@php
    $defaultView = $defaultView ?? 'cards';
    $viewUrl = function (string $view) use ($baseUrl, $viewQuery, $defaultView): string {
        $query = $view === $defaultView ? $viewQuery : array_merge($viewQuery, ['view' => $view]);
        return $baseUrl.(count($query) ? '?'.http_build_query($query) : '');
    };
    $cardsUrl = $viewUrl('cards');
    $listUrl = $viewUrl('list');
    $mapUrl = $baseUrl.'?'.http_build_query(array_merge($viewQuery, ['view' => 'map']));
    $sortQuery = request()->except(['sort', 'page', 'fragment']);
    $sortChoices = $sortChoices ?? [
        '' => 'По порядку',
        'new' => 'Сначала новые',
        'price_asc' => 'Сначала дешевле',
        'price_desc' => 'Сначала дороже',
    ];
@endphp
<div class="app-catalog-tools" aria-label="Управление каталогом">
    @if($showReset ?? true)
        @include('theme::components.catalog-reset', ['resetUrl' => $resetUrl])
    @endif
    @if($enableSorting ?? false)
        <details class="app-catalog-tools__sort">
            <summary @class(['app-catalog-tools__icon', 'is-active' => filled($sortValue ?? '')])
                     aria-label="Сортировка" title="Сортировка">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 10 4-4 4 4M7 6v12m14-4-4 4-4-4m4 4V6"/></svg>
            </summary>
            <div class="app-catalog-tools__sort-menu" role="group" aria-label="Порядок сортировки">
                @if(!empty($sortGroups))
                    @foreach($sortGroups as $group)
                        @php
                            $activeValue = ($sortValue ?? '') ?: 'name_asc';
                            $selected = in_array($activeValue, [$group['asc'], $group['desc']], true);
                            $direction = $selected ? ($activeValue === $group['asc'] ? 'asc' : 'desc') : $group['default'];
                            $opposite = $direction === 'asc' ? 'desc' : 'asc';
                            $groupUrl = fn ($value) => $baseUrl.'?'.http_build_query(array_merge($sortQuery, ['sort' => $value]));
                        @endphp
                        <div @class(['app-catalog-tools__sort-row', 'is-selected' => $selected])
                            data-venue-sort-row data-sort-asc="{{ $group['asc'] }}" data-sort-desc="{{ $group['desc'] }}"
                            data-sort-asc-hint="{{ $group['ascHint'] }}" data-sort-desc-hint="{{ $group['descHint'] }}"
                            data-sort-direction="{{ $direction }}" data-sort-selected="{{ $selected ? '1' : '0' }}">
                            <a class="app-catalog-tools__sort-name" data-venue-sort-name href="{{ $groupUrl($group[$direction]) }}"
                                @if($selected) aria-current="true" @endif
                                @if(!empty($group['distance'])) data-venue-distance-option @endif>{{ $group['label'] }}</a>
                            <a class="app-catalog-tools__sort-direction" data-venue-sort-direction href="{{ $groupUrl($group[$opposite]) }}"
                                title="{{ $group[$opposite.'Hint'] }}" aria-label="{{ $group['label'] }}: {{ $group[$opposite.'Hint'] }}"
                                @if(!empty($group['distance'])) data-venue-distance-option @endif>
                                <svg viewBox="0 0 16 16" aria-hidden="true"><path data-sort-triangle d="M4 5h8l-4 6z" @if($direction === 'asc') transform="rotate(180 8 8)" @endif/></svg>
                            </a>
                        </div>
                    @endforeach
                @else
                @foreach($sortChoices as $value => $caption)
                    @php
                        $query = $sortQuery;
                        if ($value !== '') {
                            $query['sort'] = $value;
                        }
                        $url = $baseUrl.(count($query) ? '?'.http_build_query($query) : '');
                    @endphp
                    <a href="{{ $url }}" @class(['is-selected' => ($sortValue ?? '') === $value])
                       @if($value === 'distance' && isset($venueDistanceAvailable)) data-venue-distance-option @endif
                       @if(($sortValue ?? '') === $value) aria-current="true" @endif>
                        {{ $caption }}
                        @if(($sortValue ?? '') === $value)<span aria-hidden="true">✓</span>@endif
                    </a>
                @endforeach
                @endif
            </div>
        </details>
    @endif
    <nav class="app-catalog-tools__views" aria-label="Вид каталога">
        <a @class(['app-catalog-tools__icon', 'is-active' => $currentView === 'cards'])
           href="{{ $cardsUrl }}" aria-label="Карточками" title="Карточками"
           @if($currentView === 'cards') aria-current="page" @endif>
            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        </a>
        @if($enableMapView ?? false)
            <a @class(['app-catalog-tools__icon', 'is-active' => $currentView === 'map'])
               href="{{ $mapUrl }}" aria-label="На карте" title="На карте"
               @if($currentView === 'map') aria-current="page" @endif>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3zM9 3v15M15 6v15"/></svg>
            </a>
        @else
            <a @class(['app-catalog-tools__icon', 'is-active' => $currentView === 'list'])
               href="{{ $listUrl }}" aria-label="Списком" title="Списком"
               @if($currentView === 'list') aria-current="page" @endif>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/></svg>
            </a>
        @endif
    </nav>
</div>
