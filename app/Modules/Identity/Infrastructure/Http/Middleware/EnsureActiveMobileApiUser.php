<?php

namespace App\Modules\Identity\Infrastructure\Http\Middleware;

use App\Modules\Identity\Application\Services\MobileTokenAuthorizer;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Reject cookie/web-session fallback; share the exact token rules with GraphQL. */
final class EnsureActiveMobileApiUser
{
    public function __construct(private readonly MobileTokenAuthorizer $authorizer) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $this->authorizer->authenticatedUser(
                $request->routeIs('api.v1.auth.logout') ? 'auth:logout' : 'profile:read',
            );
        } catch (AuthenticationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 401);
        } catch (AuthorizationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        return $next($request);
    }
}
