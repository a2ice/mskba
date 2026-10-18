@extends('theme::layouts.app', ['title' => $title, 'metaTitle' => $metaTitle, 'metaDescription' => $metaDescription, 'metaRobots' => $metaRobots])

@if ($builderTheme === 'mskba_app')
    @push('meta')
        <meta name="description" content="{{ $metaDescription }}">
        <meta name="robots" content="{{ $metaRobots }}">
        @unless ($builderPreview)
            <link rel="canonical" href="{{ url()->current() }}">
        @endunless
    @endpush
@endif

@push('styles')
    @vite('resources/css/page-builder.css')
    @if ($builderCss !== '')
        <style>{!! $builderCss !!}</style>
    @endif
@endpush

@push('scripts')
    @vite('resources/js/page-builder.js')
    {{-- Temporary DEV-only probe: never render on production or non-home pages. --}}
    @if (app()->environment('staging') && request()->path() === '/')
        <script defer src="{{ asset('js/scrolltop-debug.js') }}"></script>
    @endif
    @foreach ($builderScripts as $scriptUrl)
        <script defer src="{{ $scriptUrl }}"></script>
    @endforeach
@endpush

@php
    // PR 1 is intentionally DEV-only and opt-in to the existing published Hero.
    // Neither Page Builder snapshots nor production/main HTML are mutated.
    $showVenuesScene = app()->environment('staging')
        && request()->path() === '/'
        && ! $builderPreview
        && str_contains((string) $builderHtml, 'data-mskba-hero-slider')
        && str_contains((string) $builderHtml, 'mskba-hero-scene');
@endphp

@section('content')
    <div class="mskba-page-builder">
        @if ($builderPreview)
            <div class="container mskba-builder-preview-note" role="status">Предпросмотр черновика. Опубликованная страница не изменена.</div>
        @endif
        @if ($showVenuesScene)
            <div class="mskba-home-story" data-mskba-home-story>
                @include('page-builder.components.venues-scene-art')
                <div class="mskba-home-story__hero-slot">
                    {!! $builderHtml !!}
                </div>
                @include('page-builder.components.venues-scene')
            </div>
        @else
            {!! $builderHtml !!}
        @endif
    </div>
@endsection
