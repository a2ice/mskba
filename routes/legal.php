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

// Task 016: public, fixed-path fragments reuse the very same full legal
// documents shown on the canonical pages. Not user-selectable templates.
Route::get('/legal-fragments/personal-data-consent', fn () => view('legal.fragments.consent'))
    ->name('legal.fragment.consent');

Route::get('/legal-fragments/privacy', fn () => view('legal.fragments.privacy'))
    ->name('legal.fragment.privacy');

// The distribution consent is the same legal document shown by the public
// canonical page; it is never assembled from user-supplied markup.
Route::get('/legal-fragments/personal-data-distribution-consent', fn () => view('legal.fragments.distribution'))
    ->name('legal.fragment.distribution');
