<?php

namespace App\Modules\Content\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

final class NewsController extends Controller
{
    public function index(): Response|RedirectResponse
    {
        if (request()->routeIs('legacy.news.index')) {
            return redirect()->route('news.index', request()->query(), 301);
        }

        return ThemeResolver::page('news.index', [
            'contentItems' => ContentItem::query()
                ->publishedInFeed()
                ->with('cover')
                ->orderByDesc('feed_published_at')
                ->paginate(12),
        ]);
    }

    public function show(ContentItem $contentItem): Response|RedirectResponse
    {
        abort_unless(
            $contentItem->publish_in_feed
                && $contentItem->feed_published_at?->lessThanOrEqualTo(now()),
            404,
        );

        if (request()->routeIs('legacy.news.show')) {
            return redirect()->route('news.show', [
                'contentItem' => $contentItem->alias,
                ...request()->query(),
            ], 301);
        }

        $contentItem->load('cover');

        return ThemeResolver::page('news.show', compact('contentItem'));
    }
}
