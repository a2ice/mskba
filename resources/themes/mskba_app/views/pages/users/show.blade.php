@php
    $title = $publicProfile['name'];
    $breadcrumbs = [['label' => 'Участники', 'url' => route('participants.index')], ['label' => $title]];
    $activeSection = match ($publicProfile['role']) {
        'player' => 'participants.players',
        'coach' => 'participants.coaches',
        default => 'participants.index',
    };
@endphp
@extends('theme::layouts.app', ['title' => $title, 'breadcrumbs' => $breadcrumbs])

@section('content')
<section class="container app-category app-community-detail" aria-labelledby="public-user-heading">
    <div class="app-category__layout">
        <aside class="app-category__sidebar panel" aria-label="Разделы участников">
            <h2>Участники</h2>
            @include('theme::pages.participants.partials.navigation', ['activeSection' => $activeSection])
        </aside>
        <div class="app-category__main app-community-detail__main">
            @include('theme::pages.participants.partials.mobile-navigation', ['activeSection' => $activeSection])
            <article class="panel app-community-panel">
                @include('components.entity-public-banner', ['bannerUrl' => $publicProfile['banner_url'] ?? null])
                <header class="app-community-hero">
                    @include('theme::pages.participants.partials.public-avatar', ['avatarUrl' => $publicProfile['avatar_url'], 'avatarRestricted' => $publicProfile['avatar_restricted']])
                    <div class="app-community-card-content">
                        <div class="app-entity-heading">
                            <h1 id="public-user-heading">{{ $publicProfile['name'] }}</h1>
                            @include('theme::partials.entity-edit-link', ['url' => $publicProfileEditUrl ?? null])
                        </div>
                        @if(!empty($publicProfile['nickname']) && $publicProfile['nickname'] !== $publicProfile['name'])
                            <p class="muted">{{ '@'.$publicProfile['nickname'] }}</p>
                        @endif
                        <div class="app-category__badges">
                            @foreach($publicProfile['roles'] as $profileRole)
                                <span>{{ $profileRole['name'] }}</span>
                            @endforeach
                        </div>
                        @if(!empty($publicProfile['demographics']))
                            <p class="muted">{{ implode(' · ', array_filter([$publicProfile['demographics']['gender'] ?? null, isset($publicProfile['demographics']['age']) ? 'Возраст: '.$publicProfile['demographics']['age'] : null])) }}</p>
                        @endif
                    </div>
                </header>
                <div class="app-team-actions">
                    @if($isOwnProfile)
                        <a class="button secondary" href="{{ route('account') }}">Перейти в аккаунт</a>
                    @elseif(!empty($publicProfileMessageRecipientId))
                        <button class="button primary" type="button" data-direct-message-open data-recipient-id="{{ $publicProfileMessageRecipientId }}" data-recipient-name="{{ $publicProfile['name'] }}">Написать сообщение</button>
                    @endif
                </div>
            </article>
            @if(count($publicProfile['roles']))
                <nav class="panel app-community-tabs" aria-label="Роли участника">
                    <a href="{{ $publicProfile['url'] }}" @if($publicProfile['role'] === null) aria-current="page" @endif>Обзор</a>
                    @foreach($publicProfile['roles'] as $profileRole)
                        <a href="{{ $profileRole['url'] }}" @if($publicProfile['role'] === $profileRole['value']) aria-current="page" @endif>{{ $profileRole['name'] }}</a>
                    @endforeach
                </nav>
            @endif
            @foreach($publicProfile['roles'] as $profileRole)
                @if(($publicProfile['role'] === null || $publicProfile['role'] === $profileRole['value']) && filled($profileRole['description']))
                    <section class="panel app-community-panel">
                        <h2><a href="{{ $profileRole['url'] }}">{{ $profileRole['name'] }}</a></h2>
                        <p>{{ $profileRole['description'] }}</p>
                    </section>
                @endif
            @endforeach
            @if($publicProfile['role'] === null && $publicProfile['public_coach'] && count($publicProfile['sections']))
                @include('theme::pages.participants.partials.profile-block', ['block' => ['title' => 'Открытые секции', 'items' => $publicProfile['sections']]])
            @endif
            @foreach($publicProfile['blocks'] as $block)
                @if(count($block['items']))
                    @include('theme::pages.participants.partials.profile-block', ['block' => $block])
                @endif
            @endforeach
        </div>
    </div>
</section>
@if(!empty($publicProfileMessageRecipientId))
    @include('theme::pages.account.partials.direct-message-dialog')
@endif
@endsection
