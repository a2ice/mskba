@php
    $title = $title ?? 'Админка';
    $subtitle = $subtitle ?? null;
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'admin',
    'sectionClass' => 'admin-section',
    'contentTitle' => $title,
    'contentSubtitle' => $subtitle,
    'sidebarLabel' => 'Навигация админки',
    'wrapSidebarPanel' => true,
])

@section('section-sidebar')
    @include('theme::partials.admin.sidebar')
@endsection
