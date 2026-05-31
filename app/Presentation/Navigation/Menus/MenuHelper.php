<?php

namespace App\Presentation\Navigation\Menus;

trait MenuHelper
{
    /**
     * Generates a URL for the given route, handling both named routes and specific URLs/anchors.
     */
    protected function routeUrl(?string $route): ?string
    {
        if ($this->isSpecificRoute($route)) {
            return $route;
        }

        return route($route);
    }

    /**
     * Checks if the given route is active, considering both named routes and specific URLs/anchors.
     */
    protected function isActiveRoute(?string $route): bool
    {
        if ($this->isSpecificRoute($route)) {
            return false;
        }

        return request()->routeIs($route);
    }

    /**
     * Checks if the given route is a specific URL or anchor, rather than a named route.
     */
    private function isSpecificRoute(?string $route): bool
    {
        return (
            $route === null 
            || $route === '' 
            || (
                strpos($route, '#') === 0
                || strpos($route, '/') === 0
                || strpos($route, 'http://') === 0 
                || strpos($route, 'https://') === 0)
            );
    }
}