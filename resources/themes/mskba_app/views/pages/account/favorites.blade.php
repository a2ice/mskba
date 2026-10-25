@extends('theme::layouts.account', ['title' => 'Избранное'])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Избранное',
        'intro' => 'Сохраняй интересные площадки, мероприятия и команды. Удалённые элементы можно восстановить из корзины.',
    ])
@endsection

@section('account-content')
    <section class="panel app-favorites" data-favorites-account
        data-list-url="{{ route('favorites.items') }}"
        data-toggle-url="{{ route('favorites.toggle') }}">
        <header class="app-favorites__header">
            <h2>Моё избранное</h2>
            <a href="{{ route('venues.search') }}" class="button secondary">Найти площадку</a>
        </header>
        <div class="app-favorites__filters" role="group" aria-label="Тип объекта">
            <button type="button" class="chip" data-favorites-type="all" aria-pressed="true">Все</button>
            <button type="button" class="chip" data-favorites-type="venue" aria-pressed="false">Площадки</button>
            <button type="button" class="chip" data-favorites-type="event" aria-pressed="false">Мероприятия</button>
            <button type="button" class="chip" data-favorites-type="team" aria-pressed="false">Команды</button>
        </div>
        <div class="app-favorites__states" role="group" aria-label="Состояние">
            <button type="button" class="chip" data-favorites-state="active" aria-pressed="true">Избранное</button>
            <button type="button" class="chip" data-favorites-state="trashed" aria-pressed="false">Корзина</button>
        </div>
        <div data-favorites-results aria-live="polite" aria-atomic="false">
            <p class="muted">Загружаем избранное…</p>
        </div>
    </section>
@endsection
