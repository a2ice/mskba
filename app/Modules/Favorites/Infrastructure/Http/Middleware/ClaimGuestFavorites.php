<?php

namespace App\Modules\Favorites\Infrastructure\Http\Middleware;

use App\Modules\Favorites\Application\Services\FavoritesManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ClaimGuestFavorites
{
    public function __construct(private readonly FavoritesManager $favorites) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null && $request->cookie(FavoritesManager::COOKIE_NAME) !== null) {
            $this->favorites->claim($request);
        }

        return $next($request);
    }
}
