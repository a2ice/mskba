@php
    $title = 'Согласие на обработку персональных данных, разрешённых для распространения';
    $operatorName = config('legal.operator_name');
    $operatorAddress = config('legal.operator_address');
    $privacyEmail = config('legal.privacy_email');
    $consentVersion = config('legal.personal_data_distribution_consent_version');
@endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section class="legal-page first-screen">
        <div class="inner">
            <div class="mb-3">
                @include('theme::partials.breadcrumbs')
            </div>

            @include('legal.documents.distribution', ['documentClass' => 'legal-document'])
        </div>
    </section>
@endsection
