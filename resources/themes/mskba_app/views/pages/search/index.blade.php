@extends('theme::layouts.app', [
    'title' => 'Поиск по MSKBA',
    'breadcrumbs' => [['label' => 'Поиск']],
])
@push('meta')
<meta name="robots" content="noindex,follow">
@endpush

@section('content')
<section class="container app-site-search" aria-labelledby="app-site-search-heading">
    <header class="app-site-search__header">
        <h1 id="app-site-search-heading">Поиск по MSKBA</h1>
        <p class="muted">Найдите площадки, мероприятия, команды, людей, новости и товары.</p>
    </header>
    <form method="GET" action="{{ route('site.search') }}" class="panel app-site-search__form">
        <label for="app-site-search-query">Поисковый запрос</label>
        <div class="app-site-search__row">
            <input type="search" name="q" id="app-site-search-query" value="{{ $search }}"
                   maxlength="100" placeholder="Название, имя, адрес или описание" autocomplete="off">
            <button type="submit" class="button primary">Найти</button>
        </div>
        <fieldset class="app-site-search__types">
            <legend>Где искать</legend>
            @foreach ($availableTypes as $type => $caption)
                @if ($type !== 'sections' || config('features.sports_sections.enabled'))
                    <label>
                        <input type="checkbox" name="types[]" value="{{ $type }}" @checked(in_array($type, $types, true))>
                        <span>{{ $caption }}</span>
                    </label>
                @endif
            @endforeach
        </fieldset>
    </form>

    @if ($search === '')
        <div class="panel app-site-search__empty">Введите запрос, чтобы найти информацию по всему порталу.</div>
    @else
        @php($totalResults = array_sum(array_column($groups, 'count')))
        <p class="app-site-search__total" role="status">По запросу «{{ $search }}» найдено: {{ $totalResults }}</p>
        <div class="app-site-search__groups">
            @forelse ($groups as $group)
                <section class="panel app-site-search__group">
                    <div class="app-site-search__group-heading">
                        <h2>{{ $group['title'] }} <span>{{ $group['count'] }}</span></h2>
                        @if ($group['count'] > 0)
                            <a href="{{ $group['url'] }}">Все результаты</a>
                        @endif
                    </div>
                    @if ($group['count'] === 0)
                        <p class="muted">Совпадений нет.</p>
                    @else
                        <ul class="app-site-search__results">
                            @foreach ($group['items'] as $item)
                                <li>
                                    @if ($item['url'])
                                        <a href="{{ $item['url'] }}">{{ $item['title'] }}</a>
                                    @else
                                        <span>{{ $item['title'] }}</span>
                                        <small>Профиль закрыт</small>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @empty
                <section class="panel app-site-search__empty">Выберите хотя бы один раздел поиска.</section>
            @endforelse
        </div>
    @endif
</section>
@endsection
