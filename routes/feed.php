<?php

use App\Modules\Content\Presentation\Http\Controllers\NewsController;
use Illuminate\Routing\Route as RouteObject;
use Illuminate\Support\Facades\Route;

Route::prefix('feed')->group(function () {
    Route::get('/', [NewsController::class, 'index'])
        ->name('feed.index')
        ->defaults('breadcrumb', 'Новости');
    Route::get('/{contentItem:alias}', [NewsController::class, 'show'])
        ->name('feed.show');
});

$routes = Route::getRoutes();

$rename = static function (?RouteObject $route, string $name): void {
    if ($route === null) {
        return;
    }

    $action = $route->getAction();
    $action['as'] = $name;
    $route->setAction($action);
};

$rename($routes->getByName('news.index'), 'legacy.news.index');
$rename($routes->getByName('news.show'), 'legacy.news.show');
$rename($routes->getByName('feed.index'), 'news.index');
$rename($routes->getByName('feed.show'), 'news.show');

$routes->refreshNameLookups();
