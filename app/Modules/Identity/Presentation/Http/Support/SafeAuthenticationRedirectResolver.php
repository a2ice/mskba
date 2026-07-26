<?php

namespace App\Modules\Identity\Presentation\Http\Support;

use Illuminate\Http\Request;

final class SafeAuthenticationRedirectResolver
{
    public function resolve(Request $request, mixed $requestedUrl, ?string $fallbackUrl = null): string
    {
        if (is_string($requestedUrl)) {
            $requestedUrl = trim($requestedUrl);

            if (str_starts_with($requestedUrl, '/') && ! str_starts_with($requestedUrl, '//')) {
                return url($requestedUrl);
            }

            if ($this->isSameOriginUrl($requestedUrl)) {
                return $requestedUrl;
            }
        }

        $intendedUrl = $request->session()->pull('url.intended');

        if (is_string($intendedUrl) && $this->isSameOriginUrl($intendedUrl)) {
            return $intendedUrl;
        }

        if ($fallbackUrl !== null && $this->isSameOriginUrl($fallbackUrl)) {
            return $fallbackUrl;
        }

        $redirectedFrom = url()->previous();

        return $this->isSameOriginUrl($redirectedFrom) ? $redirectedFrom : url('/');
    }

    private function isSameOriginUrl(string $url): bool
    {
        $target = parse_url($url);
        $origin = parse_url(url('/'));

        if ($target === false || $origin === false) {
            return false;
        }

        return ($target['scheme'] ?? null) === ($origin['scheme'] ?? null)
            && ($target['host'] ?? null) === ($origin['host'] ?? null)
            && ($target['port'] ?? null) === ($origin['port'] ?? null);
    }
}
