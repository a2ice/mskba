<?php

use App\Modules\Content\Presentation\Http\Controllers\NewsController;
use Illuminate\Routing\Route as RouteObject;
use Illuminate\Support\Facades\Route;

$routes = Route::getRoutes();
$routes->refreshNameLookups();

$legacyIndex = $routes->getByName('news.index');
$legacyShow = $routes->getByName('news.show');

$feedIndex = Route::get('/feed', [NewsController::class, 'index'])
    ->name('feed.index')
    ->defaults('breadcrumb', 'Новости');
$feedShow = Route::get('/feed/{contentItem:alias}', [NewsController::class, 'show'])
    ->name('feed.show');

$rename = static function (?RouteObject $route, string $name): void {
    if ($route === null) {
        return;
    }

    $action = $route->getAction();
    $action['as'] = $name;
    $route->setAction($action);
};

$rename($legacyIndex, 'legacy.news.index');
$rename($legacyShow, 'legacy.news.show');
$rename($feedIndex, 'news.index');
$rename($feedShow, 'news.show');

$routes->refreshNameLookups();
