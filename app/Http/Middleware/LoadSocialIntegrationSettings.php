<?php

namespace App\Http\Middleware;

use App\Modules\Portal\Application\Services\SocialIntegrationSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LoadSocialIntegrationSettings
{
    public function handle(Request $request, Closure $next, SocialIntegrationSettings $settings = new SocialIntegrationSettings): Response
    {
        $settings->apply();

        return $next($request);
    }
}
