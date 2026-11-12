<?php

namespace App\Modules\Content\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Content\Domain\Enums\ContentStatusEnum;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Reaction\Application\Services\ReactionReadService;
use App\Modules\Reaction\Domain\Enums\ReactionSubjectTypeEnum;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

final class NewsController extends Controller
{
    public function index(ReactionReadService $reactions): Response|RedirectResponse
    {
        if (request()->routeIs('legacy.news.index')) {
            return redirect()->route('news.index', request()->query(), 301);
        }

        $validated = request()->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', \Illuminate\Validation\Rule::enum(ContentTypeEnum::class)],
        ]);
        $filters = [
            'q' => trim((string) ($validated['q'] ?? '')),
            'type' => $validated['type'] ?? '',
        ];
        $types = ContentTypeEnum::cases();

        $contentItems = ContentItem::query()
            ->publishedInFeed()
            ->with('cover')
            ->when($filters['q'] !== '', fn ($query) => $query->where(fn ($search) => $search
                ->whereLike('title', '%'.$filters['q'].'%')
                ->orWhereLike('short_description', '%'.$filters['q'].'%')))
            ->when($filters['type'] !== '', fn ($query) => $query->where('type', $filters['type']))
            ->orderByDesc('feed_published_at')
            ->paginate(12)
            ->withQueryString();

        $reactionSummaries = $reactions->forSubjects(
            ReactionSubjectTypeEnum::CONTENT,
            $contentItems->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $this->viewer(),
        );

        return ThemeResolver::page('news.index', compact('contentItems', 'reactionSummaries', 'filters', 'types'));
    }

    public function show(ContentItem $contentItem, ReactionReadService $reactions): Response|RedirectResponse
    {
        abort_unless(
            $contentItem->status === ContentStatusEnum::PUBLISHED && $contentItem->publish_in_feed
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
        $reactionSummary = $reactions->forSubject(
            ReactionSubjectTypeEnum::CONTENT,
            (int) $contentItem->id,
            $this->viewer(),
        );

        return ThemeResolver::page('news.show', compact('contentItem', 'reactionSummary'));
    }

    private function viewer(): ?User
    {
        $user = request()->user();

        return $user instanceof User ? $user : null;
    }
}
