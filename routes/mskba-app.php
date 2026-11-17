<?php

use App\Http\Middleware\HandleMskbaAppInertiaRequests;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Isolated entry point: production/other themes retain their current routes and views.
Route::middleware(HandleMskbaAppInertiaRequests::class)->group(function (): void {
    Route::get('/ui-preview', function (ThemeResolver $themes) {
        abort_unless($themes->active() === 'mskba_app', 404);

        return Inertia::render('Preview', [
            'pageTitle' => 'MSKBA App',
            'environment' => app()->environment(),
        ]);
    })->name('mskba-app.preview');
});
