<?php

namespace App\Modules\Content\Presentation\Http\Controllers;

use App\Modules\Content\Domain\Models\ContentComment;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Application\Services\UserPrivacyAccessService;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum as Privacy;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ContentCommentController
{
    public function index(Request $request, ContentItem $contentItem): JsonResponse
    {
        $this->ensurePublic($contentItem);
        $viewer = $request->user()?->canonical();
        $mine = $request->boolean('mine');
        abort_if($mine && $viewer === null, 401);
        $ids = $viewer?->identityIds() ?? [];
        $data = $request->validate(['before' => ['nullable', 'integer', 'min:1']]);
        $rows = $contentItem->comments()->with('user.profile.activeAvatar')
            ->when($mine, fn ($q) => $q->whereIn('user_id', $ids),
                fn ($q) => $q->where('is_published', true))
            ->when(isset($data['before']), fn ($q) => $q->where('id', '<', $data['before']))
            ->orderByDesc('id')->limit(11)->get();
        $comments = $rows->take(10)->values();
        $privacy = app(UserPrivacyAccessService::class);

        return $this->json([
            'comments' => $comments->map(function (ContentComment $comment) use ($ids, $viewer, $privacy): array {
                $user = $comment->user;
                $own = in_array((int) $comment->user_id, $ids, true);
                $author = $user?->nickname ?: $user?->username;
                if (! $author || filter_var($author, FILTER_VALIDATE_EMAIL)) {
                    $author = 'Игрок';
                }
                $avatarAllowed = $user && ($own || ($privacy->allows($user, $viewer, Privacy::PROFILE)
                    && $privacy->allows($user, $viewer, Privacy::AVATAR)));

                return [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'author' => $author,
                    'avatar' => $avatarAllowed ? $user?->profile?->avatarUrl() : null,
                    'published' => $comment->is_published,
                    'own' => $own,
                    'created_at' => $comment->created_at->toIso8601String(),
                    'revision' => $comment->revision,
                ];
            }),
            'next' => $rows->count() > 10 ? $comments->last()->id : null,
            'count' => $this->count($contentItem),
            'views' => (int) $contentItem->views_count,
        ]);
    }

    public function store(Request $request, ContentItem $contentItem): JsonResponse
    {
        $user = $this->actor($request, $contentItem);
        $body = $this->body($request);
        $comment = DB::transaction(function () use ($user, $contentItem, $body): ContentComment {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_if($locked->isBlocked(), 403);
            $last = $contentItem->comments()->withTrashed()->whereIn('user_id', $locked->identityIds())
                ->latest('created_at')->first();
            if ($last && $last->created_at->gt(now()->subMinutes(5))) {
                throw ValidationException::withMessages(['body' => 'Новый комментарий можно оставить через 5 минут после предыдущего.']);
            }

            return $contentItem->comments()->create(['user_id' => $locked->id, 'body' => $body]);
        });

        return $this->json(['id' => $comment->id, 'published' => false, 'count' => $this->count($contentItem)], 201);
    }

    public function update(Request $request, ContentItem $contentItem, int $comment): JsonResponse
    {
        $user = $this->actor($request, $contentItem);
        $body = $this->body($request);
        $revision = $request->validate(['revision' => ['required', 'integer', 'min:1']])['revision'];
        DB::transaction(function () use ($user, $contentItem, $comment, $body, $revision): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_if($locked->isBlocked(), 403);
            $row = $contentItem->comments()->whereIn('user_id', $locked->identityIds())->lockForUpdate()->findOrFail($comment);
            abort_if($row->revision !== (int) $revision, 409, 'Комментарий изменился. Обновите список.');
            $row->update(['body' => $body, 'is_published' => false, 'published_at' => null, 'revision' => $row->revision + 1]);
        });

        return $this->json(['published' => false, 'count' => $this->count($contentItem)]);
    }

    public function destroy(Request $request, ContentItem $contentItem, int $comment): JsonResponse
    {
        $user = $this->actor($request, $contentItem);
        DB::transaction(function () use ($user, $contentItem, $comment): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_if($locked->isBlocked(), 403);
            $contentItem->comments()->whereIn('user_id', $locked->identityIds())->lockForUpdate()->findOrFail($comment)->delete();
        });

        return $this->json(['count' => $this->count($contentItem)]);
    }

    public function moderate(Request $request, ContentItem $contentItem, int $comment): RedirectResponse
    {
        abort_unless($request->user()?->can('manage-content'), 403);
        $data = $request->validate([
            'action' => ['required', Rule::in(['publish', 'hide', 'delete'])],
            'revision' => ['required', 'integer', 'min:1'],
        ]);
        DB::transaction(function () use ($contentItem, $comment, $data): void {
            $row = $contentItem->comments()->lockForUpdate()->findOrFail($comment);
            if ($row->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Комментарий изменился. Проверьте новую версию.']);
            }
            if ($data['action'] === 'delete') {
                $row->delete();

                return;
            }
            $published = $data['action'] === 'publish';
            $row->update(['is_published' => $published, 'published_at' => $published ? now() : null, 'revision' => $row->revision + 1]);
        });

        return redirect(route('admin.content.edit', $contentItem->alias).'#content-comments-moderation')->with('status', 'Комментарий обновлён.');
    }

    private function ensurePublic(ContentItem $contentItem): void
    {
        abort_unless(ContentItem::query()->publishedInFeed()->whereKey($contentItem->id)->exists(), 404);
    }

    private function actor(Request $request, ContentItem $contentItem): User
    {
        $this->ensurePublic($contentItem);
        $user = $request->user()?->canonical();
        abort_unless($user instanceof User, 401);
        abort_if($user->isBlocked(), 403);

        return $user;
    }

    private function body(Request $request): string
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:3000']]);
        $body = trim($data['body']);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Напишите комментарий.']);
        }

        return $body;
    }

    private function count(ContentItem $contentItem): int
    {
        return $contentItem->comments()->where('is_published', true)->count();
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }
}
