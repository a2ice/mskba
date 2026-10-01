<?php

use App\Modules\Content\Presentation\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/sitemap.xml', SitemapController::class)
    ->name('seo.sitemap');
