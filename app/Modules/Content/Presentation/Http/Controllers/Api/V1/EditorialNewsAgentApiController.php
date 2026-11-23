<?php

namespace App\Modules\Content\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Content\Application\Services\ContentCoverManager;
use App\Modules\Content\Application\Services\ContentPublicationManager;
use App\Modules\Content\Domain\Enums\ContentFormatEnum;
use App\Modules\Content\Domain\Enums\ContentStatusEnum;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Domain\Models\TelegramChat;
use App\Modules\Telegram\Domain\Models\TelegramContentPublication;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramContentPublicationJob;
use App\Modules\Vk\Application\Services\VkContentPublicationManager;
use App\Modules\Vk\Application\Services\VkWallPublisher;
use App\Modules\Vk\Domain\Models\VkContentPublication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'meta_keywords' => ['nullable', 'string', 'max:1000'],
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
                'meta_title' => trim($data['meta_title'] ?? '') ?: $data['title'],
                'meta_description' => trim($data['meta_description'] ?? '')
                    ?: mb_substr(trim(preg_replace('/\s+/u', ' ', $data['summary']) ?? $data['summary']), 0, 320),
                'meta_keywords' => trim($data['meta_keywords'] ?? '') ?: 'баскетбол, Москва, MSKBA',
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

    public function destinations(Request $request): JsonResponse
    {
        $this->authorizeAgent($request, 'editorial-news:publish');
        $this->authorizeAgent($request, 'editorial-news:social');
        $telegram = $this->productionSocial() && filled(config('telegram.bot_token'));
        $vk = VkWallPublisher::wallConfigured();

        return response()->json(['data' => [
            'telegram' => $telegram ? TelegramChat::query()->where('is_active', true)
                ->orderBy('title')->orderBy('id')->get(['id', 'title', 'type']) : [],
            'vk' => [
                'available' => VkWallPublisher::configured(),
                'can_defer' => (bool) $vk,
                'group_id' => $vk ? (int) config('vk.wall.group_id') : null,
            ],
        ]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function publish(
        Request $request,
        string $alias,
        ContentPublicationManager $telegram,
        VkContentPublicationManager $vk,
    ): JsonResponse {
        $actor = $this->authorizeAgent($request, 'editorial-news:publish');
        $data = $request->validate([
            'telegram_chat_ids' => ['sometimes', 'array', 'max:100'],
            'telegram_chat_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'publish_in_vk' => ['sometimes', 'boolean'],
            'defer_vk_until_authorized' => ['sometimes', 'boolean', 'prohibited_unless:publish_in_vk,true'],
            'vk_group_id' => ['required_if:publish_in_vk,true', 'prohibited_unless:publish_in_vk,true', 'integer', 'min:1'],
        ]);

        $chatIds = array_map('intval', $data['telegram_chat_ids'] ?? []);
        sort($chatIds);
        $sendVk = (bool) ($data['publish_in_vk'] ?? false);
        $deferVk = $sendVk && (bool) ($data['defer_vk_until_authorized'] ?? false);
        $groupId = $sendVk ? (int) $data['vk_group_id'] : null;
        $explicit = $request->hasAny(['telegram_chat_ids', 'publish_in_vk', 'vk_group_id', 'defer_vk_until_authorized']);

        if ($explicit) {
            $this->authorizeAgent($request, 'editorial-news:social');
        }
        $item = DB::transaction(function () use ($actor, $alias, $telegram, $vk, $chatIds, $sendVk, $groupId, $explicit, $deferVk): ContentItem {
            $item = $this->items($actor)->where('alias', $alias)->lockForUpdate()->firstOrFail();
            if ($item->status === ContentStatusEnum::PUBLISHED && $item->publish_in_feed) {
                if ($explicit) {
                    $existing = $item->telegramPublications()->where('is_enabled', true)
                        ->pluck('chat_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
                    $existingVk = VkContentPublication::query()->where('content_item_id', $item->id)
                        ->where('is_enabled', true)->first();
                    abort_unless($existing === $chatIds && ($existingVk ? (int) $existingVk->group_id : null) === $groupId, 409,
                        'Получатели уже опубликованного материала не изменяются через этот API.');
                }

                return $item;
            }
            abort_unless($item->status === ContentStatusEnum::DRAFT, 409);
            abort_if($item->publish_in_telegram, 409);

            if ($chatIds !== [] && (! $this->productionSocial() || blank(config('telegram.bot_token'))
                || TelegramChat::query()->where('is_active', true)->whereIn('id', $chatIds)->count() !== count($chatIds))) {
                throw ValidationException::withMessages(['telegram_chat_ids' => 'Выберите активные подключённые Telegram-чаты на production.']);
            }
            if ($sendVk && (! VkWallPublisher::wallConfigured() || (! $deferVk && ! VkWallPublisher::photoTokenAvailable())
                || $groupId !== (int) config('vk.wall.group_id') || ! $item->cover()->exists())) {
                throw ValidationException::withMessages(['publish_in_vk' => 'ВКонтакте недоступен, изменилось сообщество или отсутствует обложка.']);
            }

            $item->update([
                'updated_by_user_id' => $actor->id,
                'status' => ContentStatusEnum::PUBLISHED,
                'publish_in_feed' => true,
                'publish_in_telegram' => $chatIds !== [],
                'feed_published_at' => now(),
            ]);
            if ($chatIds !== []) {
                $telegram->syncTelegramChats($item, $chatIds);
            }
            if ($sendVk) {
                $vk->sync($item, true);
            }

            return $item;
        });

        return $this->response($item);
    }


    /**
     * Requeue only a definitely rejected Telegram delivery (or an edit with a known message_id).
     * Transport errors and missing Telegram receipts may have succeeded remotely: fail closed.
     */
    public function retryTelegram(Request $request, string $alias, string $chatId): JsonResponse
    {
        $actor = $this->authorizeAgent($request, 'editorial-news:publish');
        $this->authorizeAgent($request, 'editorial-news:social');
        abort_unless($this->productionSocial(), 403);

        $publicationId = DB::transaction(function () use ($actor, $alias, $chatId): ?int {
            $item = $this->items($actor)->where('alias', $alias)->lockForUpdate()->firstOrFail();
            abort_unless($item->status === ContentStatusEnum::PUBLISHED
                && $item->publish_in_feed && $item->publish_in_telegram, 409);

            $publication = $item->telegramPublications()->where('chat_id', (int) $chatId)
                ->lockForUpdate()->firstOrFail();
            abort_unless($publication->is_enabled && $publication->chat?->is_active
                && filled(config('telegram.bot_token')), 409);

            // Repeated requests while pending or after delivery are strictly no-ops.
            if (in_array($publication->status, ['pending', 'published'], true)) {
                return null;
            }

            abort_unless($publication->status === 'failed'
                && ($publication->message_id !== null || $this->definitiveTelegramRejection($publication->last_error)), 409,
                'Delivery outcome is not proven; reconcile the Telegram message before retrying.');

            $publication->forceFill(['status' => 'pending'])->save();

            return (int) $publication->id;
        });

        if ($publicationId !== null) {
            SyncTelegramContentPublicationJob::dispatch($publicationId);
        }

        return $this->response($this->items($actor)->where('alias', $alias)->firstOrFail());
    }

    private function definitiveTelegramRejection(?string $error): bool
    {
        return is_string($error)
            && preg_match('/^Telegram API returned HTTP (400|401|403|404|413)\./', $error) === 1;
    }

    private function telegramErrorCode(?string $error): ?string
    {
        if (! filled($error)) {
            return null;
        }

        $lower = mb_strtolower($error);
        if (str_contains($lower, 'chat not found')) {
            return 'chat_not_found';
        }
        if (str_contains($lower, 'bot was kicked') || str_contains($lower, 'bot is not a member')
            || str_contains($lower, 'not enough rights') || str_contains($lower, 'not enough permissions')
            || str_contains($lower, 'forbidden')) {
            return 'bot_access_denied';
        }
        if (str_contains($lower, 'too many requests') || str_contains($lower, '429')) {
            return 'rate_limited';
        }
        if (str_contains($lower, 'connection failed') || str_contains($lower, 'did not return message_id')) {
            return 'outcome_unknown';
        }

        return $this->definitiveTelegramRejection($error) ? 'telegram_rejected' : 'delivery_error';
    }

    private function productionSocial(): bool
    {
        return config('app.env') === 'production'
            && parse_url((string) config('app.url'), PHP_URL_HOST) === 'mskba.ru';
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

        $bridge = $token instanceof PersonalAccessToken
            && str_starts_with((string) $token->name, 'mskba-editorial-news:bridge-');
        if ($bridge) {
            $abilities = $token->abilities ?? [];
            sort($abilities);
            abort_unless($this->productionSocial()
                && $abilities === ['editorial-news:publish', 'editorial-news:social', 'editorial-news:write'], 403);
        }

        abort_unless(
            $actor instanceof User
            && $actor->username === 'dev'
            && $actor->system_role === UserSystemRoleEnum::SYSTEM
            && ! $actor->isBlocked() && ! $actor->trashed()
            && $token instanceof PersonalAccessToken
            && str_starts_with((string) $token->name, 'mskba-editorial-news:')
            && $token->expires_at !== null
            && $token->expires_at->isFuture()
            && $token->expires_at->lessThanOrEqualTo(now()->addDays($bridge ? 30 : 7))
            && in_array($ability, $token->abilities ?? [], true),
            403,
        );

        return $actor;
    }

    private function response(ContentItem $item, int $status = 200): JsonResponse
    {
        $vk = VkContentPublication::query()->where('content_item_id', $item->id)->first();

        return response()->json([
            'data' => [
                'id' => $item->id,
                'alias' => $item->alias,
                'status' => $item->status->value,
                'title' => $item->title,
                'seo' => [
                    'meta_title' => $item->meta_title ?: $item->title,
                    'meta_description' => $item->meta_description ?: $item->short_description,
                    'meta_keywords' => $item->meta_keywords,
                ],
                'has_cover' => $item->cover()->exists(),
                'telegram_publications' => $item->telegramPublications()->where('is_enabled', true)
                    ->orderBy('chat_id')->get(['chat_id', 'status', 'message_id', 'last_error'])
                    ->map(fn (TelegramContentPublication $publication): array => [
                        'chat_id' => (int) $publication->chat_id,
                        'status' => $publication->status,
                        'message_id' => $publication->message_id,
                        'error_code' => $publication->status === 'failed'
                            ? $this->telegramErrorCode($publication->last_error) : null,
                        'retry_safe' => $publication->status === 'failed'
                            && ($publication->message_id !== null
                                || $this->definitiveTelegramRejection($publication->last_error)),
                    ])->values(),
                'vk_publication' => $vk && $vk->is_enabled ? [
                    'group_id' => $vk->group_id,
                    'status' => $vk->status,
                    'post_id' => $vk->post_id,
                ] : null,
                'url' => $item->status === ContentStatusEnum::PUBLISHED && $item->publish_in_feed
                    ? $item->publicUrl()
                    : null,
            ],
        ], $status, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
