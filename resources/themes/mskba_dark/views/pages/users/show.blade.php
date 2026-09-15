@extends('theme::layouts.section-sidebar', ['title' => $publicProfile['name'], 'contentTitle' => $publicProfile['name'], 'sectionId' => 'user-profile', 'sidebarLabel' => 'Профиль'])

@section('section-sidebar')
    <ul class="sidebar-nav nav flex-column">
        <li class="nav-item"><a class="nav-link" href="{{ $publicProfile['url'] }}">Профиль</a></li>
        @foreach($publicProfile['roles'] as $role)
            <li class="nav-item"><a class="nav-link {{ $publicProfile['role'] === $role['value'] ? 'active' : '' }}" href="{{ $role['url'] }}">{{ $role['name'] }}</a></li>
        @endforeach
    </ul>
@endsection

@section('section-content')
    <article class="public-user-profile">
        @if($publicProfile['avatar_url'])
            <img class="public-user-profile__avatar" src="{{ $publicProfile['avatar_url'] }}" alt="{{ $publicProfile['name'] }}">
        @else
            <div
                class="public-user-profile__avatar public-user-profile__avatar--placeholder"
                @if($publicProfile['avatar_restricted']) title="Отображение аватара запрещено в настройках профиля" data-tooltip-variant="title" @endif
            ><i class="ti ti-user" aria-hidden="true"></i></div>
        @endif
        @foreach($publicProfile['roles'] as $role)
            @if($publicProfile['role'] === null || $publicProfile['role'] === $role['value'])
                <section><h2><a href="{{ $role['url'] }}">{{ $role['name'] }}</a></h2><p>{{ $role['description'] }}</p></section>
            @endif
        @endforeach
        @if($publicProfile['role'] === null && $publicProfile['public_coach'])
            <section><h2>Открытые секции</h2><ul>@foreach($publicProfile['sections'] as $section)<li><a href="{{ $section['url'] }}">{{ $section['name'] }}</a></li>@endforeach</ul></section>
        @endif
        @foreach($publicProfile['blocks'] as $block)
            <section><h2>{{ $block['title'] }}</h2>
                <ul>@forelse($block['items'] as $item)<li>@if(isset($item['url']))<a href="{{ $item['url'] }}">{{ $item['name'] }}</a>@else{{ $item['name'] }}@endif</li>@empty<li>Пока нет данных.</li>@endforelse</ul>
            </section>
        @endforeach
    </article>
@endsection
