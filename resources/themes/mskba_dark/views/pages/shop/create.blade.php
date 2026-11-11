@php($title = 'Добавить товар')
@extends('theme::layouts.app', ['title' => $title])
@section('content')
<section class="inner" style="padding-block:60px 100px">
    <h1>Добавить товар</h1>
    <p>Форма добавления товара в разработке. Пока товары добавляются администраторами через Filament.</p>
    <a class="btn btn--secondary" href="{{ route('shop.index') }}">Вернуться в магазин</a>
</section>
@endsection
