<?php

namespace App\Modules\Content\Presentation\Http;

use App\Http\Controllers\Controller;
use App\Modules\Content\Application\Services\PageBuilderRenderer;
use App\Modules\Content\Application\Services\PageBuilderSources;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Presentation\Theming\ThemeResolver;
use App\Presentation\Theming\DesignThemeManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;

final class PageBuilderController extends Controller
{
    public function home(PageBuilderRenderer $renderer): ?Response
    {
        try {
            $page = $this->publishedForPath('/');
        } catch (QueryException $exception) {
            if (! Schema::hasTable('page_builder_pages')) {
                return null; // Zero-downtime deploy: existing homepage remains available.
            }
            throw $exception;
        }
        return $page ? $this->page($page, $renderer, false) : null;
    }

    public function dynamic(string $builderSlug, PageBuilderRenderer $renderer): Response
    {
        $page = $this->publishedForPath('/'.$builderSlug);
        abort_unless($page !== null, 404);
        return $this->page($page, $renderer, false);
    }

    /** Used by the reviewed public route override; not an editor preview. */
    public function published(PageBuilderPage $page, PageBuilderRenderer $renderer): Response
    {
        abort_unless($page->published_at !== null, 404);
        $matched = $this->publishedForPath((string) $page->published_path);
        abort_unless($matched !== null, 404);

        return $this->page($matched, $renderer, false);
    }

    /**
     * Explicit theme wins; the unscoped published page is the fallback.
     * Never leak another theme's page when no fallback exists.
     */
    private function publishedForPath(string $path): ?PageBuilderPage
    {
        $themeId = app(DesignThemeManager::class)->current()->getKey();
        $pages = PageBuilderPage::query()
            ->where('published_path', $path)
            ->whereNotNull('published_at');

        if ($themeId === null) {
            return $pages->whereNull('published_design_theme_id')->first();
        }

        return $pages
            ->where(fn (Builder $query) => $query->where('published_design_theme_id', $themeId)
                ->orWhereNull('published_design_theme_id'))
            ->orderByRaw('CASE WHEN published_design_theme_id = ? THEN 0 ELSE 1 END', [$themeId])
            ->first();
    }

    public function preview(PageBuilderPage $page, PageBuilderRenderer $renderer): Response
    {
        $user = auth('web')->user();
        abort_unless($user && app(BackofficePermissionManager::class)->allows($user, Permission::BACKOFFICE_ACCESS)
            && app(BackofficePermissionManager::class)->allows($user, Permission::BACKOFFICE_BUILDER_VIEW), 403);
        return $this->page($page, $renderer, true)
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function data(Request $request, string $source, PageBuilderSources $sources): JsonResponse
    {
        abort_unless($sources->isRegistered($source), 404);
        if ($sources->requiresLogin($source)) {
            abort_unless($request->user(), 401);
        }
        $items = $sources->load($source, $request->user(), $request->integer('limit', 5));
        return response()->json(['items' => $items])
            ->header('Cache-Control', 'private, no-store');
    }

    private function page(PageBuilderPage $page, PageBuilderRenderer $renderer, bool $preview): Response
    {
        $output = $renderer->render($page, $preview);
        return response()->view('page-builder.show', [
            'title' => $preview ? $page->name : ($page->published_name ?: $page->name),
            'metaTitle' => $preview
                ? ($page->meta_title ?: $page->name)
                : ($page->published_meta_title ?: $page->published_name),
            'metaDescription' => $preview ? $page->meta_description : $page->published_meta_description,
            'metaRobots' => $preview ? 'noindex, nofollow' : 'index, follow',
            'builderHtml' => $output['html'],
            'builderCss' => $output['css'],
            'builderCssOriginal' => $output['originalCss'],
            'builderLqipMap' => $output['lqip'],
            'builderScripts' => $output['scripts'],
            'builderPreview' => $preview,
            'builderTheme' => app(ThemeResolver::class)->active(),
        ]);
    }
}
