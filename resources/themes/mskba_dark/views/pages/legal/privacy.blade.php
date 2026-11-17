@php
    $title = 'Политика обработки персональных данных';
    $operatorName = config('legal.operator_name');
    $operatorAddress = config('legal.operator_address');
    $privacyEmail = config('legal.privacy_email');
    $policyVersion = config('legal.privacy_policy_version');
@endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section class="legal-page first-screen">
        <div class="inner">
            <div class="mb-3">
                @include('theme::partials.breadcrumbs')
            </div>

            @include('legal.documents.privacy')
        </div>
    </section>
@endsection
