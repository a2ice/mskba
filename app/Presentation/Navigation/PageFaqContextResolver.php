<?php

namespace App\Presentation\Navigation;

/**
 * Resolve a page *type* from its URL, independently of the current
 * light/dark design theme. The route patterns come from a single catalog.
 */
final class PageFaqContextResolver
{
    public function pageKey(): ?string
    {
        $path = '/'.ltrim(request()->path(), '/');
        $bestKey = null;
        $bestScore = -1;

        foreach (config('faq-page-types', []) as $key => $page) {
            foreach ($page['routes'] ?? [] as $pattern) {
                if (! is_string($pattern) || ! $this->matches($pattern, $path)) {
                    continue;
                }

                // Prefer more specific patterns, e.g. /venues/create
                // over /venues/{alias}, and nested details over parents.
                $static = preg_replace('/\{[^}]+\}/', '', $pattern);
                $score = (substr_count($pattern, '/') * 1000) + strlen((string) $static);
                if ($score > $bestScore) {
                    $bestKey = (string) $key;
                    $bestScore = $score;
                }
            }
        }

        return $bestKey;
    }

    private function matches(string $pattern, string $path): bool
    {
        $parts = preg_split('/(\{[^}]+\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (! is_array($parts)) {
            return false;
        }
        $expression = '';
        foreach ($parts as $part) {
            $expression .= preg_match('/^\{[^}]+\}$/', $part)
                ? '[^/]+'
                : preg_quote($part, '#');
        }
        return preg_match('#^'.$expression.'$#uD', $path) === 1;
    }
}
