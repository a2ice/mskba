@extends('theme::layouts.account', ['title' => 'Мои игры'])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Мои игры',
        'intro' => 'Игры и игровые тренировки: твои заявки, приглашения, участия и история.',
    ])
@endsection

@section('account-content')
    <section class="app-my-games account-collection account-collection--games" aria-labelledby="my-games-heading">
        <div class="app-my-games__top">
            <h2 id="my-games-heading">Мои игры и игровые тренировки</h2>
            <a class="account-collection__action" href="{{ route('events.index', ['type' => 'games']) }}">Найти игру</a>
        </div>
        <nav class="app-my-games__filters account-collection__tabs" aria-label="Фильтр участия">
            @foreach (['all' => 'Все', ...$groups] as $key => $name)
                <a href="{{ route('account.my-games', $key === 'all' ? [] : ['scope' => $key]) }}"
                   @if($scope === $key) aria-current="page" @endif
                   class="chip {{ $scope === $key ? 'app-my-games__filter--active' : '' }}">{{ $name }}</a>
            @endforeach
        </nav>
        @if ($games->isEmpty())
            <p class="muted" role="status">Здесь пока нет игр или игровых тренировок с твоим участием{{ $scope !== 'all' ? ' по выбранному фильтру' : '' }}.</p>
            <a class="account-collection__action account-collection__action--primary" href="{{ route('events.index', ['type' => 'games']) }}">Посмотреть игры</a>
        @else
            @foreach($scope === 'all' ? $groups : [$scope => $groups[$scope]] as $group => $heading)
                @php($rows = $games->getCollection()->where('group', $group))
                @if($rows->isNotEmpty())
                    <section class="app-my-games__group" aria-labelledby="my-games-{{ $group }}">
                        <h3 id="my-games-{{ $group }}">{{ $heading }}</h3>
                        <div class="app-my-games__list account-collection__cards">
                            @foreach($rows as $item)
                                @php($event = $item['event'])
                                <article class="app-my-games__card account-collection__card">
                                    <div class="app-my-games__card-head account-collection__card-head">
                                        <span class="badge">{{ $event->type->label() }}</span>
                                        @if($item['tournament']) <span class="badge">Турнирная игра</span> @endif
                                        <span class="app-my-games__status account-collection__status">{{ $item['label'] }}</span>
                                    </div>
                                    <h4><a href="{{ route('events.show', $event->routeIdentifier()) }}">{{ $event->title }}</a></h4>
                                    <div class="app-my-games__meta">
                                        <span>{{ $event->starts_at->timezone(config('app.timezone', 'Europe/Moscow'))->format('d.m.Y H:i') }}</span>
                                        @if($event->venue)<span>{{ $event->venue->name }}</span>@endif
                                    </div>
                                    <a class="button secondary account-collection__action" href="{{ route('events.show', $event->routeIdentifier()) }}">Открыть мероприятие</a>
                                </article>
                            @endforeach
                        </div>
                    </section>
                @endif
            @endforeach
            <div class="app-my-games__pagination">{{ $games->links() }}</div>
        @endif
    </section>
@endsection
