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
    @foreach ($builderScripts as $scriptUrl)
        <script defer src="{{ $scriptUrl }}"></script>
    @endforeach
@endpush

@section('content')
    <div class="mskba-page-builder">
        @if ($builderPreview)
            <div class="container mskba-builder-preview-note" role="status">Предпросмотр черновика. Опубликованная страница не изменена.</div>
        @endif
        {!! $builderHtml !!}
    </div>
    @if ($builderTheme === 'mskba_app')
        @include('theme::partials.venue-map-dialog')
    @endif
@endsection
