<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RedirectGuestsToPortalLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth('web')->check()) {
            return redirect()->guest(route('login'));
        }

        return $next($request);
    }
}
