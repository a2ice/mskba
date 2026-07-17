<?php

namespace App\Presentation\Breadcrumbs;

use Illuminate\Support\Facades\Route;

final class BreadcrumbsResolver
{
    /**
     * @return array<int, array{label: string, url: string, active: bool, visible: bool}>
     */
    public function resolve(?string $page, ?array $breadcrumbs = null): array
    {
        $title = $page ?? 'Страница';
        $routeName = request()->route()?->getName();
        $routes = app('router')->getRoutes();

        $items = $breadcrumbs;

        if ($items === null) {
            $items = [];
            $routeNames = [];

            if ($routeName) {
                $segments = explode('.', $routeName);

                foreach (array_keys($segments) as $index) {
                    $candidate = implode('.', array_slice($segments, 0, $index + 1));
                    $sectionIndex = $candidate.'.index';
                    $name = match (true) {
                        $candidate === $routeName && Route::has($candidate) => $candidate,
                        Route::has($candidate) => $candidate,
                        Route::has($sectionIndex) => $sectionIndex,
                        default => null,
                    };

                    if ($name && ! in_array($name, $routeNames, true)) {
                        $routeNames[] = $name;
                    }
                }
            }

            foreach ($routeNames as $name) {
                $route = $routes->getByName($name);
                $items[] = [
                    'label' => $route?->defaults['breadcrumb'] ?? ($name === $routeName ? $title : \Illuminate\Support\Str::headline(\Illuminate\Support\Str::afterLast($name, '.'))),
                    'url' => $name === $routeName ? null : route($name),
                ];
            }

            if ($items === []) {
                $items[] = ['label' => $title];
            }
        }

        $items = $items instanceof \Illuminate\Support\Collection ? $items->all() : $items;
        $trail = array_merge(
            [['label' => 'Главная', 'url' => route('welcome')]],
            $items
        );

        return $trail;

    }
}
