<?php

namespace App\Modules\Content\Presentation\Http;

use App\Http\Controllers\Controller;
use App\Modules\Content\Application\Services\PageBuilderRenderer;
use App\Modules\Content\Application\Services\PageBuilderSources;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Presentation\Theming\ThemeResolver;
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
            $page = PageBuilderPage::query()->where('published_path', '/')->whereNotNull('published_at')->first();
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
        $page = PageBuilderPage::query()->where('published_path', '/'.$builderSlug)
            ->whereNotNull('published_at')->firstOrFail();
        return $this->page($page, $renderer, false);
    }

    /** Used by the reviewed public route override; not an editor preview. */
    public function published(PageBuilderPage $page, PageBuilderRenderer $renderer): Response
    {
        abort_unless($page->published_at !== null, 404);

        return $this->page($page, $renderer, false);
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
            'builderLqipMap' => $output['lqip'],
            'builderScripts' => $output['scripts'],
            'builderPreview' => $preview,
            'builderTheme' => app(ThemeResolver::class)->active(),
        ]);
    }
}
