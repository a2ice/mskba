<?php

namespace App\Modules\Content\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Content\Domain\Enums\ContentStatusEnum;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Content\Domain\Models\ContentTag;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

final class FaqController extends Controller
{
    public function index(): Response
    {
        $contentItems = ContentItem::query()
            ->published()
            ->where('type', ContentTypeEnum::FAQ)
            ->with('tags')
            ->orderByRaw('system_key is null')
            ->orderBy('title')
            ->get();

        return ThemeResolver::page('faq.index', compact('contentItems'));
    }

    public function creation(string $topic): Response
    {
        $contentItem = ContentItem::query()
            ->where('type', ContentTypeEnum::FAQ)
            ->where('system_key', 'faq.creation.'.$topic)
            ->first();

        if ($contentItem) {
            abort_unless($contentItem->status === ContentStatusEnum::PUBLISHED, 404);

            return $this->render($contentItem);
        }

        $guide = config('creation-guides.'.$topic);
        abort_unless(is_array($guide), 404);

        return ThemeResolver::page('faq.creation', compact('guide'));
    }

    public function welcome(): Response
    {
        $contentItem = ContentItem::query()
            ->where('type', ContentTypeEnum::FAQ)
            ->where('system_key', 'faq.welcome')
            ->first();

        if ($contentItem) {
            abort_unless($contentItem->status === ContentStatusEnum::PUBLISHED, 404);

            return $this->render($contentItem);
        }

        return ThemeResolver::page('faq.welcome');
    }

    public function show(ContentItem $contentItem): Response
    {
        abort_unless($contentItem->type === ContentTypeEnum::FAQ && $contentItem->status === ContentStatusEnum::PUBLISHED, 404);

        return $this->render($contentItem);
    }

    public function search(Request $request): JsonResponse
    {
        $terms = $this->meaningfulTerms($request->string('q')->toString());

        if ($terms === []) {
            return response()->json(['results' => []]);
        }

        $tags = ContentTag::query()
            ->whereHas('contentItems', fn ($query) => $query->published()->where('type', ContentTypeEnum::FAQ))
            ->where(function ($query) use ($terms): void {
                foreach ($terms as $term) {
                    $query->orWhere('normalized_name', 'like', '%'.$term.'%');
                }
            })
            ->with(['contentItems' => function ($query): void {
                $query
                    ->published()
                    ->where('type', ContentTypeEnum::FAQ)
                    ->orderBy('title');
            }])
            ->orderBy('name')
            ->limit(8)
            ->get();

        return response()->json([
            'results' => $tags->map(fn (ContentTag $tag): array => [
                'tag' => $tag->name,
                'articles' => $tag->contentItems->take(6)->map(fn (ContentItem $item): array => [
                    'title' => $item->title,
                    'description' => Str::limit($item->short_description, 140),
                    'url' => $item->publicUrl(),
                ])->values()->all(),
            ])->values()->all(),
        ]);
    }

    private function render(ContentItem $contentItem): Response
    {
        $contentItem->load(['tags', 'cover', 'inlineImages']);

        return ThemeResolver::page('faq.show', compact('contentItem'));
    }

    /** @return list<string> */
    private function meaningfulTerms(string $query): array
    {
        $query = Str::lower(trim($query));
        $query = preg_replace('/[^\pL\pN\s-]+/u', ' ', $query) ?? $query;
        $stopWords = ['как', 'что', 'где', 'когда', 'куда', 'зачем', 'почему', 'ли', 'можно', 'нужно', 'мне'];

        return collect(preg_split('/\s+/u', $query) ?: [])
            ->map(fn (string $term): string => trim($term))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 2 && ! in_array($term, $stopWords, true))
            ->unique()
            ->take(4)
            ->values()
            ->all();
    }
}
