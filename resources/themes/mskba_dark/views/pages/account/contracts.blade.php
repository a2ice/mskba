@extends('theme::layouts.section-sidebar', [
    'title' => 'Мои контракты',
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => 'Мои контракты',
    'contentSubtitle' => 'Контракты на площадки, команды, секции и другие сущности.',
    'sidebarLabel' => 'Навигация аккаунта',
    'wrapSidebarPanel' => false,
    'sidebarPartial' => 'theme::partials.account.sidebar',
])
@section('section-content')
    @include('account.partials.contracts-index')
@endsection
