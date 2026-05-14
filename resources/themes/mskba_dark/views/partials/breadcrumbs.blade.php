<!-- breadcrumbs partial получает данные о текущей странице и если не главная отображает иначе не показывает -->
@php
    // получаем из контроллера название страницы, если не передано, то используем дефолтное
    $title = $title ?? 'Страница';
@endphp

@if (!request()->routeIs('home'))
    <div class="page-breadcrumbs">
        <a href="{{ route('home') }}" class="page-breadcrumbs__link">Главная</a>
        <span class="page-breadcrumbs__separator">/</span>
        <span class="page-breadcrumbs__current">{{ $title }}</span>
    </div>
@endif