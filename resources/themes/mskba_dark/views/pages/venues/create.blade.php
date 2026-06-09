@php
    $title = 'Добавить площадку';

    // guest, confirmed, unconfirmed different messages
    if(auth()->guest()) {
        $contentSubtitle = 'Чтобы добавить площадку, необходимо войти на сайт.';
    } elseif (!auth()->user()->isConfirmed()) {
        $contentSubtitle = 'Чтобы добавить площадку, необходимо подтвердить аккаунт.';
    } else {
        $contentSubtitle = 'Находите и добавляйте баскетбольные площадки по всей Москве и области.';
    }
@endphp


@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'venues',
    'sectionClass' => 'venues-section',
    'contentTitle' => $title,
    'contentSubtitle' => $contentSubtitle,
    'sidebarLabel' => 'Навигация площадок',
])

@section('section-sidebar')
    <div class="section-sidebar-block">
        @include('theme::partials.menu.sidebar', ['page' => 'venues'])
    </div>
@endsection

@section('section-content')
    @if(session('error'))
        <div class="alert alert-danger mb-3">
            {{ session('error') }}
        </div>
    @endif

    @guest
        <div class="section-access-panel">
            <button
                type="button"
                class="btn btn--primary btn--sm js-handler"
                data-handler="modal"
                data-modal-action="open"
                data-modal-target="auth-entry-classic"
            >
                Войти
            </button>
        </div>
    @else
        @if(auth()->user()->isConfirmed())
            @include('theme::partials.venues.form', [
                'types' => $types,
                'action' => route('venues.store'),
                'cancelUrl' => route('venues'),
            ])
        @else
            <div class="section-access-panel">
                <a href="{{ route('account.confirmation') }}" class="btn btn--primary btn--sm">Подтвердить аккаунт</a>
            </div>
        @endif
    @endguest
@endsection
