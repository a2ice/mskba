@php
    $title = 'Политика обработки персональных данных';
    $operatorName = config('legal.operator_name');
    $operatorAddress = config('legal.operator_address');
    $privacyEmail = config('legal.privacy_email');
    $policyVersion = config('legal.privacy_policy_version');
@endphp
@include('legal.documents.privacy')
