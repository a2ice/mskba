<?php

namespace App\Presentation\Navigation;

final class ContextSubmenuResolver
{
    /**
     * More-specific exact paths win over regex patterns; amongst patterns, a
     * longer match wins. A child may opt in to its parent's items explicitly.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolve(): array
    {
        $path = '/'.ltrim(request()->path(), '/');
        $sections = config('submenu.sections', []);
        $matches = [];

        foreach ($sections as $key => $section) {
            if (! is_array($section) || ! $this->matchesRoute($section)) {
                continue;
            }

            if (in_array($path, $section['exact'] ?? [], true)) {
                $score = 100000 + strlen($path);
            } else {
                $pattern = $section['pattern'] ?? null;
                if (! is_string($pattern) || @preg_match($pattern, $path, $found) !== 1) {
                    continue;
                }
                $score = strlen($found[0]);
            }

            $matches[] = ['key' => $key, 'score' => $score, 'section' => $section];
        }

        usort($matches, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        if ($matches === []) {
            return [];
        }

        $selected = $matches[0];
        $items = $this->items($selected['section']);
        if ($selected['section']['include_parent'] ?? false) {
            $parent = $selected['section']['parent'] ?? null;
            if (is_string($parent) && isset($sections[$parent]) && is_array($sections[$parent])) {
                $items = array_merge($items, $this->items($sections[$parent]));
            }
        }

        return $items;
    }

    /** @param array<string, mixed> $section */
    private function matchesRoute(array $section): bool
    {
        $routes = $section['routes'] ?? [];
        if ($routes === []) {
            return true;
        }

        foreach ($routes as $route) {
            if (request()->routeIs($route)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $section
     *  @return array<int, array<string, mixed>>
     */
    private function items(array $section): array
    {
        $handlerClass = $section['handler'] ?? null;
        if (! is_string($handlerClass) || ! class_exists($handlerClass)) {
            return [];
        }
        $handler = app($handlerClass);
        if (! $handler instanceof MenuHandler) {
            return [];
        }
        return array_values(array_filter($handler->items(), static fn (array $item): bool => ($item['visible'] ?? true) === true));
    }
}
