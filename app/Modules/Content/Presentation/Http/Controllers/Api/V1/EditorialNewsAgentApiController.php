<?php

namespace App\Modules\Content\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Content\Application\Services\ContentCoverManager;
use App\Modules\Content\Domain\Enums\ContentFormatEnum;
use App\Modules\Content\Domain\Enums\ContentStatusEnum;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

final class EditorialNewsAgentApiController extends Controller
{
    public function store(Request $request, ContentCoverManager $covers): JsonResponse
    {
        $actor = $this->authorizeAgent($request, 'editorial-news:write');
        $data = $request->validate([
            'alias' => ['required', 'string', 'max:230', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', Rule::notIn(ContentItem::RESERVED_ALIASES), Rule::unique(ContentItem::class, 'alias')],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['required', 'string', 'max:1000'],
            'content' => ['required', 'string', 'max:50000'],
            'cover' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
        ]);

        $item = DB::transaction(function () use ($actor, $data, $request, $covers): ContentItem {
            $item = ContentItem::query()->create([
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
                'type' => ContentTypeEnum::MATERIAL,
                'alias' => $data['alias'],
                'title' => $data['title'],
                'short_description' => $data['summary'],
                'full_description' => $data['content'],
                'content_format' => ContentFormatEnum::MARKDOWN,
                'status' => ContentStatusEnum::DRAFT,
                'publish_in_feed' => false,
                'publish_in_telegram' => false,
                'feed_published_at' => null,
            ]);
            if ($request->hasFile('cover')) {
                $covers->replace($item, $request->file('cover')->get());
            }

            return $item;
        });

        return $this->response($item, 201);
    }

    public function show(Request $request, string $alias): JsonResponse
    {
        $actor = $this->authorizeAgent($request, 'editorial-news:write');

        return $this->response($this->items($actor)->where('alias', $alias)->firstOrFail());
    }

    public function publish(Request $request, string $alias): JsonResponse
    {
        $actor = $this->authorizeAgent($request, 'editorial-news:publish');
        $item = DB::transaction(function () use ($actor, $alias): ContentItem {
            $item = $this->items($actor)->where('alias', $alias)->lockForUpdate()->firstOrFail();
            if ($item->status === ContentStatusEnum::PUBLISHED && $item->publish_in_feed) {
                return $item;
            }
            abort_unless($item->status === ContentStatusEnum::DRAFT, 409);
            abort_if($item->publish_in_telegram, 409);
            $item->update([
                'updated_by_user_id' => $actor->id,
                'status' => ContentStatusEnum::PUBLISHED,
                'publish_in_feed' => true,
                'feed_published_at' => now(),
            ]);

            return $item;
        });

        return $this->response($item);
    }

    private function items(User $actor): \Illuminate\Database\Eloquent\Builder
    {
        return ContentItem::query()->where('created_by_user_id', $actor->id)
            ->where('type', ContentTypeEnum::MATERIAL)->whereNull('system_key');
    }

    private function authorizeAgent(Request $request, string $ability): User
    {
        $actor = $request->user();
        $token = $actor?->currentAccessToken();

        abort_unless(
            $actor instanceof User
            && $actor->username === 'dev'
            && $actor->system_role === UserSystemRoleEnum::SYSTEM
            && ! $actor->isBlocked() && ! $actor->trashed()
            && $token instanceof PersonalAccessToken
            && str_starts_with((string) $token->name, 'mskba-editorial-news:')
            && $token->expires_at !== null
            && $token->expires_at->isFuture()
            && $token->expires_at->lessThanOrEqualTo(now()->addDays(7))
            && in_array($ability, $token->abilities ?? [], true),
            403,
        );

        return $actor;
    }

    private function response(ContentItem $item, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => $item->id,
                'alias' => $item->alias,
                'status' => $item->status->value,
                'title' => $item->title,
                'has_cover' => $item->cover()->exists(),
                'url' => $item->status === ContentStatusEnum::PUBLISHED && $item->publish_in_feed
                    ? $item->publicUrl()
                    : null,
            ],
        ], $status, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
