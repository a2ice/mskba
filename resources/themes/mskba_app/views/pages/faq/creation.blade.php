@php
    $title = $guide['heading'];
    $metaDescription = $guide['intro'];
@endphp
@extends('theme::layouts.app', ['title' => $title])
@section('content')
<section class="container app-content-page" aria-labelledby="faq-guide-heading">
    <nav><a href="{{ route('faq.index') }}">Все инструкции FAQ</a></nav>
    <article class="panel app-content-page__article">
        <h1 id="faq-guide-heading">{{ $guide['heading'] }}</h1>
        <p class="app-content-page__lead">{{ $guide['intro'] }}</p>
        @if(!empty($guide['requirements']))
            <section class="app-content-page__requirements" aria-labelledby="guide-requirements-heading">
                <h2 id="guide-requirements-heading">Условия создания</h2>
                <ul>
                    @foreach($guide['requirements'] as $requirement)
                        <li>
                            {{ $requirement['text'] }}
                            @foreach($requirement['links'] ?? [] as $link)
                                <a href="{{ route($link['route']).(!empty($link['anchor']) ? '#'.$link['anchor'] : '') }}" target="_blank" rel="noopener">{{ $link['label'] }}</a>
                            @endforeach
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
        <ol>
            @foreach($guide['steps'] as $step)<li>{{ $step }}</li>@endforeach
        </ol>
        <a class="button secondary" href="{{ route($guide['catalog']) }}">{{ $guide['catalog_label'] }}</a>
    </article>
</section>
@endsection
