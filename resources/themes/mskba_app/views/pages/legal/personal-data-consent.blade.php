@extends('theme::layouts.app', ['title' => 'Согласие на обработку персональных данных'])

@section('content')
    <section class="section mskba-legal-page" aria-label="Согласие на обработку персональных данных">
        <div class="container mskba-legal-page__inner">
            @include('legal.fragments.consent')
        </div>
    </section>
@endsection
