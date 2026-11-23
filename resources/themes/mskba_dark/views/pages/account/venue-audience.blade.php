@extends('theme::layouts.section-sidebar', [
    'title' => 'Интересуются · '.$venue->name, 'sectionId' => 'account', 'sectionClass' => 'account-section',
    'contentTitle' => 'Интересуются', 'contentSubtitle' => $venue->name,
    'sidebarLabel' => 'Навигация аккаунта', 'wrapSidebarPanel' => false, 'sidebarPartial' => 'theme::partials.account.sidebar',
])
@section('section-content')
    @include('account.partials.venue-audience')
@endsection
