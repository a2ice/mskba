<?php

namespace App\Presentation\Navigation;

final class FaqContextResolver
{
    public function context(): string
    {
        $route = (string) request()->route()?->getName();
        $path = '/'.ltrim(request()->path(), '/');
        if (str_starts_with($route, 'venues.') || $route === 'venues' || preg_match('#^/venues(?:/|$)#', $path)) {
            return 'venues';
        }
        if (str_starts_with($route, 'account.') || $route === 'account') {
            return 'account';
        }
        foreach (['events', 'teams', 'tournaments', 'sections', 'coordination'] as $section) {
            if (str_starts_with($route, $section.'.') || $route === $section || str_starts_with($path, '/'.$section.'/') || $path === '/'.$section) {
                return $section;
            }
        }
        return '';
    }
}
