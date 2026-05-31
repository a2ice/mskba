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

        if (strpos($route, ',') !== false) {
            $patterns = $this->routePatterns($route);
            foreach ($patterns as $pattern) {
                if (request()->routeIs($pattern)) {
                    return true;
                }
            }
            return false;
        }

        return request()->routeIs($route);
    }

    /**
     * @return array<int, string>
     */
    private function routePatterns(string $route): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $route)),
            fn (string $pattern): bool => $pattern !== '',
        ));
    }

    /**
     * Checks if the given route is a specific URL or anchor, rather than a named route.
     */
    private function isSpecificRoute(?string $route): bool
    {
        return
            $route === null
            || $route === ''
            || (
                strpos($route, '#') === 0
                || strpos($route, '/') === 0
                || strpos($route, 'http://') === 0
                || strpos($route, 'https://') === 0);
    }
}
