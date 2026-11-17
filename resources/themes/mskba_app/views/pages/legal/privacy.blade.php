@extends('theme::layouts.app', ['title' => 'Политика обработки персональных данных'])

@section('content')
    <section class="section mskba-legal-page" aria-label="Политика обработки персональных данных">
        <div class="container mskba-legal-page__inner">
            @include('legal.fragments.privacy')
        </div>
    </section>
@endsection
