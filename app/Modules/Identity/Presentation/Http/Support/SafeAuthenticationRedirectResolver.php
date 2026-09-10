<?php

namespace App\Modules\Identity\Presentation\Http\Support;

use Illuminate\Http\Request;

final class SafeAuthenticationRedirectResolver
{
    public function resolve(Request $request, mixed $requestedUrl, ?string $fallbackUrl = null): string
    {
        $plannedUrl = $this->peek($request, $requestedUrl);

        if ($plannedUrl !== null) {
            $this->forgetIntended($request);

            return $plannedUrl;
        }

        $fallbackUrl = $this->normalizeReturnUrl($fallbackUrl);
        if ($fallbackUrl !== null) {
            return $fallbackUrl;
        }

        $redirectedFrom = $this->normalizeReturnUrl(url()->previous());

        return $redirectedFrom ?? url('/');
    }

    public function resolvePreservingIntended(
        Request $request,
        mixed $requestedUrl,
        ?string $fallbackUrl = null,
    ): string {
        $plannedUrl = $this->peek($request, $requestedUrl);

        if ($plannedUrl !== null) {
            return $plannedUrl;
        }

        $fallbackUrl = $this->normalizeReturnUrl($fallbackUrl);
        if ($fallbackUrl !== null) {
            return $fallbackUrl;
        }

        $redirectedFrom = $this->normalizeReturnUrl(url()->previous());

        return $redirectedFrom ?? url('/');
    }

    public function peek(Request $request, mixed $requestedUrl = null): ?string
    {
        $requestedUrl = $this->normalizeReturnUrl($requestedUrl);
        if ($requestedUrl !== null) {
            return $requestedUrl;
        }

        return $this->normalizeReturnUrl($request->session()->get('url.intended'));
    }

    public function rememberIntended(Request $request, mixed $requestedUrl): ?string
    {
        $requestedUrl = $this->normalizeReturnUrl($requestedUrl);
        if ($requestedUrl === null) {
            return null;
        }

        $request->session()->put('url.intended', $requestedUrl);

        return $requestedUrl;
    }

    public function forgetIntended(Request $request): void
    {
        $request->session()->forget('url.intended');
    }

    private function normalizeReturnUrl(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            $url = url($url);
        }

        if (! $this->isSameOriginUrl($url) || $this->isAuthenticationEntryUrl($url)) {
            return null;
        }

        return $url;
    }

    private function isAuthenticationEntryUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return false;
        }

        $path = '/'.ltrim(rtrim($path, '/'), '/');

        return in_array($path, ['/login', '/register', '/logout', '/auth'], true)
            || str_starts_with($path, '/auth/');
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
