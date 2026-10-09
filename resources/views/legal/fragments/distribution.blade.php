@php
    $title = 'Согласие на обработку персональных данных, разрешённых для распространения';
    $operatorName = config('legal.operator_name');
    $operatorAddress = config('legal.operator_address');
    $privacyEmail = config('legal.privacy_email');
    $consentVersion = config('legal.personal_data_distribution_consent_version');
    $distributionTypes = \App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum::distributionTypes();
@endphp
@include('legal.documents.distribution')
