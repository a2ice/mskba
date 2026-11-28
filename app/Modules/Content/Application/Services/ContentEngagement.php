<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Models\ContentComment;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Favorites\Application\Services\FavoritesManager;
use App\Modules\Favorites\Domain\Models\Favorite;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

final readonly class ContentEngagement
{
    public function __construct(private FavoritesManager $favorites) {}

    /** @param Collection<int, ContentItem> $items */
    public function summaries(Collection $items, Request $request): array
    {
        $ids = $items->pluck('id')->all();
        if ($ids === []) {
            return [];
        }
        $likes = Favorite::query()->where('entity_type', 'content')->whereNotNull('user_id')
            ->whereNull('trashed_at')->whereIn('entity_id', $ids)
            ->selectRaw('entity_id, COUNT(*) AS total')->groupBy('entity_id')->pluck('total', 'entity_id');
        $mine = $this->favorites->owned($request)->where('entity_type', 'content')
            ->whereNull('trashed_at')->whereIn('entity_id', $ids)->pluck('entity_id')->all();
        $comments = ContentComment::query()->where('is_published', true)->whereIn('content_item_id', $ids)
            ->selectRaw('content_item_id, COUNT(*) AS total')->groupBy('content_item_id')->pluck('total', 'content_item_id');

        return $items->mapWithKeys(fn (ContentItem $item) => [$item->id => [
            'views' => (int) $item->views_count,
            'likes' => (int) ($likes[$item->id] ?? 0),
            'liked' => in_array($item->id, $mine, true),
            'comments' => (int) ($comments[$item->id] ?? 0),
        ]])->all();
    }

    public function recordView(ContentItem $item, Request $request): void
    {
        if (! $request->isMethod('GET') || ! $request->hasSession()
            || ! $request->routeIs('news.show') || $request->query('preview')
            || $request->headers->has('X-Purpose')
            || preg_match('/prefetch|prerender|preview/i', (string) $request->header('Purpose').' '.$request->header('Sec-Purpose'))
            || preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|curl|wget/i', (string) $request->userAgent())
            || blank($request->userAgent())) {
            return;
        }

        // Session or canonical account, not IP/fingerprint. Only an expiring
        // cache key is retained; no historical visitor-level analytics.
        $viewer = $request->user()?->canonical();
        $actor = $viewer ? 'user:'.$viewer->id : 'session:'.$request->session()->getId();
        $key = 'content-view:v1:'.$item->id.':'.hash_hmac('sha256', $actor, (string) config('app.key'));
        try {
            if (Cache::add($key, true, now()->addMinutes(30))) {
                try {
                    ContentItem::query()->whereKey($item->id)->publishedInFeed()->increment('views_count', 1, ['updated_at' => $item->updated_at]);
                } catch (Throwable $e) {
                    Cache::forget($key);
                    throw $e;
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
