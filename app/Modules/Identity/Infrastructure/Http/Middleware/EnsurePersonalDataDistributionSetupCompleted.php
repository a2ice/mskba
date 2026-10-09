<?php

namespace App\Modules\Identity\Infrastructure\Http\Middleware;

use App\Modules\Identity\Application\Services\PersonalDataDistributionConsentService;
use App\Modules\Identity\Presentation\Http\Support\SafeAuthenticationRedirectResolver;
use App\Presentation\Theming\ThemeResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePersonalDataDistributionSetupCompleted
{
    public function __construct(
        private readonly PersonalDataDistributionConsentService $consents,
        private readonly SafeAuthenticationRedirectResolver $redirects,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $route = $request->route();

        if ($user === null || $route === null || ! $this->consents->requiresSetup($user)) {
            return $next($request);
        }

        // Public pages and signed integrations are not onboarding-protected.
        // Only the authenticated portal routes are subject to this checkpoint.
        $authenticated = collect($route->gatherMiddleware())->contains(
            static fn (string $middleware): bool => $middleware === 'auth'
                || str_starts_with($middleware, 'auth:'),
        );

        if (! $authenticated || $request->routeIs(
            'account.privacy.distribution',
            'account.privacy.distribution.update',
            'auth.logout',
            'logout',
        )) {
            return $next($request);
        }

        $setupUrl = route('account.privacy.distribution');

        // The new closable onboarding flow is isolated to MSKBA App.
        // Existing production themes retain their mandatory redirect.
        $appTheme = app(ThemeResolver::class)->active() === 'mskba_app';
        if ($appTheme && ($request->isMethod('GET') || $request->isMethod('HEAD'))) {
            return $next($request);
        }

        if (! $appTheme && $request->isMethod('GET')) {
            $target = $this->redirects->peek($request, $request->fullUrl());
            if ($target !== null && ! $request->session()->has('privacy.distribution.return_to')) {
                $request->session()->put('privacy.distribution.return_to', $target);
            }
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Сначала завершите настройку приватности аккаунта.',
                'redirect_url' => $setupUrl,
                'code' => 'ONBOARDING_REQUIRED',
            ], 409);
        }

        // Non-JS forms retain a safe fallback: 303 never replays a mutation
        // after the redirect. MSKBA App catches form submissions in-browser.
        if ($appTheme) {
            return redirect()->to(route('account'), 303)
                ->with('onboarding_required', 'Завершите регистрацию перед сохранением изменений.');
        }

        return redirect()->to($setupUrl, 303);
    }
}
