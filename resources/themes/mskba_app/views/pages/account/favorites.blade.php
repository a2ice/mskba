@extends('theme::layouts.account', ['title' => 'Избранное'])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Избранное',
        'intro' => 'Сохраняй интересные площадки, мероприятия и команды. Удалённые элементы можно восстановить из корзины.',
    ])
@endsection

@section('account-content')
    <section class="app-favorites account-collection" data-favorites-account
        data-list-url="{{ route('favorites.items') }}"
        data-toggle-url="{{ route('favorites.toggle') }}">
        <form class="app-favorites__filter-form account-collection__filters" data-favorites-filters aria-label="Фильтры избранного">
            <label class="account-collection__field" for="account-favorites-type">
                <span>Тип объекта</span>
                <span class="select-control">
                    <select id="account-favorites-type" data-favorites-type name="type">
                    <option value="all">Все типы</option>
                    <option value="venue">Площадки</option>
                    <option value="event">Мероприятия</option>
                    <option value="team">Команды</option>
                </select>
                    @include('account.partials.native-select-chevron')
                </span>
            </label>
            <label class="account-collection__field" for="account-favorites-state">
                <span>Статус</span>
                <span class="select-control">
                    <select id="account-favorites-state" data-favorites-state name="state">
                    <option value="active">Избранное</option>
                    <option value="trashed">Корзина</option>
                </select>
                    @include('account.partials.native-select-chevron')
                </span>
            </label>
            <button type="reset" class="account-collection__reset" data-favorites-reset>Сбросить</button>
        </form>
        <p class="account-collection__count" data-favorites-count role="status" aria-live="polite"></p>
        <div class="account-collection__cards" data-favorites-results aria-live="polite" aria-atomic="false">
            <p class="muted">Загружаем избранное…</p>
        </div>
    </section>
@endsection
