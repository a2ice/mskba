<?php

use App\Modules\Content\Presentation\Http\Controllers\NewsController;
use Illuminate\Support\Facades\Route;

$routes = Route::getRoutes();

foreach (['news.index', 'news.show'] as $routeName) {
    $legacyRoute = $routes->getByName($routeName);

    if ($legacyRoute === null) {
        continue;
    }

    $action = $legacyRoute->getAction();
    $action['as'] = 'legacy.'.$routeName;
    $legacyRoute->setAction($action);
}

$routes->refreshNameLookups();

Route::prefix('feed')->group(function () {
    Route::get('/', [NewsController::class, 'index'])
        ->name('news.index')
        ->defaults('breadcrumb', 'Новости');
    Route::get('/{contentItem:alias}', [NewsController::class, 'show'])
        ->name('news.show');
});
