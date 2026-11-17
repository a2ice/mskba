@extends('theme::layouts.section-sidebar', [
    'title' => 'Специальные условия', 'sectionId' => 'account', 'sectionClass' => 'account-section',
    'contentTitle' => 'Специальные условия: '.$venue->name, 'sidebarLabel' => 'Навигация аккаунта',
    'wrapSidebarPanel' => false, 'sidebarPartial' => 'theme::partials.account.sidebar',
])
@section('section-content')
    @include('account.partials.special-terms-content', ['specialButtonClass' => 'btn btn--secondary btn--sm'])
@endsection
