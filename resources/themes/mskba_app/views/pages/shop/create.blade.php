@php
    $title = 'Добавить товар';
    $breadcrumbs = [
        ['label' => 'Магазин', 'url' => route('shop.index')],
        ['label' => $title, 'url' => null],
    ];
@endphp
@extends('theme::layouts.app', ['title' => $title, 'breadcrumbs' => $breadcrumbs])
@section('content')
<section class="container app-shop app-shop--create" aria-labelledby="shop-create-title">
    <header class="app-shop__heading">
        <div>
            <h1 id="shop-create-title">Добавить товар</h1>
            <p class="muted">Размещение товаров пользователями появится позднее.</p>
        </div>
        <a class="button secondary" href="{{ route('shop.index') }}">Вернуться в магазин</a>
    </header>
    <div class="panel app-shop__empty">
        <h2>Форма добавления товара в разработке</h2>
        <p class="muted">Пока товары добавляются администраторами через Filament.</p>
    </div>
</section>
@endsection
