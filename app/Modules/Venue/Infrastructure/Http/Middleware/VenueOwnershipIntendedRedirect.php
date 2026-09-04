<?php

namespace App\Modules\Venue\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VenueOwnershipIntendedRedirect
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->routeIs('account.confirmation.complete')
            || ! $response instanceof RedirectResponse
            || ! $request->user()?->canonical()->isConfirmed()) {
            return $response;
        }

        $intended = $request->session()->pull('url.intended');
        if (! is_string($intended) || $intended === '') {
            return $response;
        }

        return redirect()
            ->to($intended)
            ->with('status', 'Аккаунт подтвержден. Теперь можно продолжить подтверждение управления площадкой.');
    }
}
