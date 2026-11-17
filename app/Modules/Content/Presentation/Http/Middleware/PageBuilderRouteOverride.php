<?php

namespace App\Modules\Content\Presentation\Http\Middleware;

use App\Modules\Content\Application\Services\PageBuilderRenderer;
use App\Modules\Content\Application\Services\PageBuilderRouteRegistry;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Content\Presentation\Http\PageBuilderController;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web-group middleware: gates dynamically on exact public, unguarded, named
 * GET/HEAD routes. Existing controllers, app middleware and nested routes stay intact.
 */
final class PageBuilderRouteOverride
{
    public function __construct(private readonly PageBuilderRouteRegistry $registry) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        $path = '/'.ltrim($request->path(), '/');
        if (! is_string($routeName) || ! $this->registry->mayOverride($routeName, $path)) {
            return $next($request);
        }

        try {
            $page = PageBuilderPage::query()
                ->where('published_path', $path)
                ->where('published_route_name', $routeName)
                ->where('published_route_type', 'public_index')
                ->where('cms_override_enabled', true)
                ->whereNotNull('published_at')
                ->first();
        } catch (QueryException $exception) {
            // Mirrors the home Page Builder zero-downtime migration guard.
            // Never mask other DB errors or accidentally override the legacy route.
            if (! Schema::hasTable('page_builder_pages')) {
                return $next($request);
            }
            throw $exception;
        }

        if ($page === null) {
            return $next($request);
        }

        return app(PageBuilderController::class)->published($page, app(PageBuilderRenderer::class));
    }
}
