@extends('theme::layouts.app', ['title' => "Площадка: $alias"])

@section('content')
    <section id="venue" class="venue-section py-5">
        <div class="container">
            <div class="section-heading">
                <h1 class="mb-4">Площадка: {{ $alias }}</h1>
            </div>

            <div class="section-content">
                <p>Здесь будет отображаться информация о площадке с alias '{{ $alias }}'.</p>
            </div>
        </div>
    </section>
@endsection
