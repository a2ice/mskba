@php
    $title = 'Согласие на обработку персональных данных, разрешённых для распространения';
    $operatorName = config('legal.operator_name');
    $operatorAddress = config('legal.operator_address');
    $privacyEmail = config('legal.privacy_email');
    $consentVersion = config('legal.personal_data_distribution_consent_version');
@endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section class="privacy-onboarding privacy-onboarding__legal" aria-label="Документ согласия">
        <div class="container">
            @include('legal.documents.distribution', ['documentClass' => 'panel privacy-onboarding__legal-document legal-document'])
        </div>
    </section>
@endsection
