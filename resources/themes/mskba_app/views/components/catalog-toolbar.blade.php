@php
    $cardsUrl = $baseUrl.(count($viewQuery) ? '?'.http_build_query($viewQuery) : '');
    $listUrl = $baseUrl.'?'.http_build_query(array_merge($viewQuery, ['view' => 'list']));
    $sortQuery = request()->except(['sort', 'page']);
    $sortChoices = [
        '' => 'По порядку',
        'new' => 'Сначала новые',
        'price_asc' => 'Сначала дешевле',
        'price_desc' => 'Сначала дороже',
    ];
@endphp
<div class="app-catalog-tools" aria-label="Управление каталогом">
    <a class="app-catalog-tools__icon app-catalog-tools__reset"
       href="{{ $resetUrl }}" title="Сбросить фильтры" aria-label="Сбросить фильтры">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3v8h8"/><path d="M3.6 11A9 9 0 1 1 6 18.3"/></svg>
    </a>
    @if($enableSorting ?? false)
        <details class="app-catalog-tools__sort">
            <summary @class(['app-catalog-tools__icon', 'is-active' => filled($sortValue ?? '')])
                     aria-label="Сортировка" title="Сортировка">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 10 4-4 4 4M7 6v12m14-4-4 4-4-4m4 4V6"/></svg>
            </summary>
            <div class="app-catalog-tools__sort-menu" role="group" aria-label="Порядок сортировки">
                @foreach($sortChoices as $value => $caption)
                    @php
                        $query = $sortQuery;
                        if ($value !== '') {
                            $query['sort'] = $value;
                        }
                        $url = $baseUrl.(count($query) ? '?'.http_build_query($query) : '');
                    @endphp
                    <a href="{{ $url }}" @class(['is-selected' => ($sortValue ?? '') === $value])
                       @if(($sortValue ?? '') === $value) aria-current="true" @endif>
                        {{ $caption }}
                        @if(($sortValue ?? '') === $value)<span aria-hidden="true">✓</span>@endif
                    </a>
                @endforeach
            </div>
        </details>
    @endif
    <nav class="app-catalog-tools__views" aria-label="Вид каталога">
        <a @class(['app-catalog-tools__icon', 'is-active' => $currentView === 'cards'])
           href="{{ $cardsUrl }}" aria-label="Карточками" title="Карточками"
           @if($currentView === 'cards') aria-current="page" @endif>
            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        </a>
        <a @class(['app-catalog-tools__icon', 'is-active' => $currentView === 'list'])
           href="{{ $listUrl }}" aria-label="Списком" title="Списком"
           @if($currentView === 'list') aria-current="page" @endif>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/></svg>
        </a>
    </nav>
</div>
