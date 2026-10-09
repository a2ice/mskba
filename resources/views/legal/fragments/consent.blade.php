@php
    $title = 'Согласие на обработку персональных данных';
    $operatorName = config('legal.operator_name');
    $operatorAddress = config('legal.operator_address');
    $privacyEmail = config('legal.privacy_email');
    $consentVersion = config('legal.personal_data_consent_version');
@endphp
@include('legal.documents.consent')
