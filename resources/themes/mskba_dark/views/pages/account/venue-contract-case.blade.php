@extends('theme::layouts.section-sidebar', [
    'title' => 'Полномочия: '.$venue->name,
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => 'Управление площадкой',
    'contentSubtitle' => $venue->name,
    'sidebarLabel' => 'Навигация аккаунта',
    'wrapSidebarPanel' => false,
    'sidebarPartial' => 'theme::partials.account.sidebar',
])
@section('section-content')
    @include('account.partials.venue-contract-case')
@endsection
