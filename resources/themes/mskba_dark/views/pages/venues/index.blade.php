@php
    $title = 'Площадки';
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'venues',
    'sectionClass' => 'venues-section',
    'contentTitle' => $title,
    'contentSubtitle' => 'Баскетбольные площадки по всей Москве и области.',
    'sidebarLabel' => 'Навигация площадок',
])

@section('section-heading-action')
    <a href="{{ route('venues.create') }}" class="btn btn--primary-bordered btn--sm">Добавить площадку</a>
@endsection

@section('section-sidebar')
    <div class="section-sidebar-block">
        @include('theme::partials.menu.sidebar', ['page' => 'venues', 'sidebarTitle' => 'Площадки'])
    </div>

    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Фильтры</h2>
        <p class="section-sidebar-block__text">
            Здесь появятся фильтры по типу площадки, статусу, району и доступности расписания.
        </p>
    </div>
@endsection

@section('section-content')
    @if ($venues === [])
        <div class="alert alert-info">
            Площадки пока не назначены.
        </div>
    @else
        <div class="section-list">
            @foreach ($venues as $venue)
                <article class="section-list-item">
                    <h2 class="h5 mb-3">
                        <a href="{{ route('venues.show', $venue->alias) }}">
                            {{ $venue->name }}
                        </a>
                    </h2>
                    <p class="mb-2">Статус: <span class="badge badge--{{ $venue->status == 'confirmed' ? 'success' : 'danger' }}">{{ $venue->status }}</span></p>
                    <p class="mb-3">Описание: {{ $venue->shortDescription }}</p>
                    @if($venue->rawAddress)
                        <p class="mb-3">Адрес: {{ $venue->rawAddress }}</p>
                    @endif
                    <div class="d-flex gap-2">
                        <a href="{{ route('venues.show', $venue->alias) }}" class="btn btn--secondary btn--sm">Подробнее</a>
                        @if ($venue->canEdit)
                            <a href="{{ route('venues.edit', $venue->alias) }}" class="btn btn--primary btn--sm">Редактировать</a>
                        @endif
                        @if ($venue->canRemove)
                            <a href="{{ route('venues.remove', $venue->alias) }}" class="btn btn--danger btn--sm">Удалить</a>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif
@endsection
