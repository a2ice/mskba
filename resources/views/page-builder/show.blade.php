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
        <style data-mskba-lqip-css>{!! $builderCss !!}</style>
        @if ($builderCssOriginal !== $builderCss)
            <noscript><style>{!! $builderCssOriginal !!}</style></noscript>
        @endif
    @endif
@endpush

@push('scripts')
    @vite('resources/js/page-builder.js')
    @foreach ($builderScripts as $scriptUrl)
        <script defer src="{{ $scriptUrl }}"></script>
    @endforeach
@endpush

@section('content')
    <noscript><style>.mskba-page-builder img[data-mskba-lqip-full-src]{display:none!important}</style></noscript>
    <div class="mskba-page-builder">
        @if ($builderPreview)
            <div class="container mskba-builder-preview-note" role="status">Предпросмотр черновика. Опубликованная страница не изменена.</div>
        @endif
        {!! $builderHtml !!}
    </div>
    @if (! empty($builderLqipMap))
        {{-- Server-generated manifest only: never insert editor HTML or code here. --}}
        <script type="application/json" data-page-builder-lqip-manifest>@json($builderLqipMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES)</script>
    @endif
    @if ($builderTheme === 'mskba_app')
        @include('theme::partials.venue-map-dialog')
    @endif
@endsection
