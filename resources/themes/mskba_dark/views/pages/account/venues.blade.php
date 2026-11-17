@extends('theme::layouts.section-sidebar', [
    'title' => 'Мои площадки',
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => 'Мои площадки',
    'contentSubtitle' => 'Площадки, по которым у вас есть или были контракты.',
    'sidebarLabel' => 'Навигация аккаунта',
    'wrapSidebarPanel' => false,
    'sidebarPartial' => 'theme::partials.account.sidebar',
])
@section('section-heading-action')
    <a href="{{ route('venues.create') }}" class="btn btn--primary btn--sm">Добавить площадку</a>
@endsection
@section('section-content')
    @include('account.partials.venues-index')
@endsection
