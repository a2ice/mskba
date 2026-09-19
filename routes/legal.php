<?php

use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Support\Facades\Route;

$themeResolver = app(ThemeResolver::class);

Route::get('/personal-data-consent', function () use ($themeResolver) {
    return $themeResolver->page('legal.personal-data-consent');
})->name('personal-data.consent')->defaults('breadcrumb', 'Согласие на обработку персональных данных');


Route::get('/personal-data-distribution-consent', function () use ($themeResolver) {
    return $themeResolver->page('legal.personal-data-distribution-consent', [
        'distributionTypes' => UserPrivacySettingTypeEnum::distributionTypes(),
    ]);
})->name('personal-data.distribution-consent')->defaults('breadcrumb', 'Согласие на распространение персональных данных');
