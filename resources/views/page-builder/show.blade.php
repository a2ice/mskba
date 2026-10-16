@extends('theme::layouts.app', ['title' => $title, 'metaTitle' => $metaTitle, 'metaDescription' => $metaDescription, 'metaRobots' => $metaRobots])

@if ($builderTheme === 'mskba_app')
    @push('meta')
        <meta name="description" content="{{ $metaDescription }}">
        <meta name="robots" content="{{ $metaRobots }}">
    @endpush
@endif

@section('content')
    @vite(['resources/css/page-builder.css', 'resources/js/page-builder.js'])
    @if ($builderCss !== '')
        <style>{!! $builderCss !!}</style>
    @endif
    <div class="mskba-page-builder">
        @if ($builderPreview)
            <div class="mskba-builder-preview-note" role="status">Предпросмотр черновика. Опубликованная страница не изменена.</div>
        @endif
        {!! $builderHtml !!}
    </div>
@endsection
