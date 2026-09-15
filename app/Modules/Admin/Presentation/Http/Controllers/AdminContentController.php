<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Content\Application\Services\ContentBodySanitizer;
use App\Modules\Content\Application\Services\ContentCoverManager;
use App\Modules\Content\Application\Services\ContentInlineImageManager;
use App\Modules\Content\Application\Services\ContentPublicationManager;
use App\Modules\Content\Application\Services\ContentTagManager;
use App\Modules\Content\Domain\Enums\ContentFormatEnum;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Content\Presentation\Http\Requests\SaveContentItemRequest;
use App\Modules\Content\Presentation\Http\Requests\StoreContentInlineImageRequest;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Telegram\Domain\Models\TelegramChat;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use App\Support\Text\CyrillicTransliterator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AdminContentController extends Controller
{
    public function index(Request $request): Response
    {
        $query = ContentItem::query()
            ->with(['createdBy.profile', 'telegramPublications', 'tags']);

        if ($search = trim($request->string('q')->toString())) {
            $query->where(function ($query) use ($search): void {
                $query
                    ->where('title', 'ilike', '%'.$search.'%')
                    ->orWhere('alias', 'ilike', '%'.$search.'%')
                    ->orWhere('short_description', 'ilike', '%'.$search.'%')
                    ->orWhereHas('tags', fn ($tags) => $tags->where('normalized_name', 'ilike', '%'.Str::lower($search).'%'));
            });
        }

        if ($type = ContentTypeEnum::tryFrom($request->string('type')->toString())) {
            $query->where('type', $type);
        }

        if ($request->string('feed')->toString() === 'published') {
            $query->where('publish_in_feed', true);
        } elseif ($request->string('feed')->toString() === 'hidden') {
            $query->where('publish_in_feed', false);
        }

        if ($request->string('telegram')->toString() === 'published') {
            $query->where('publish_in_telegram', true);
        } elseif ($request->string('telegram')->toString() === 'hidden') {
            $query->where('publish_in_telegram', false);
        }

        return ThemeResolver::page('admin.content.index', [
            'contentItems' => $query
                ->latest()
                ->paginate(20)
                ->withQueryString(),
            'types' => ContentTypeEnum::cases(),
        ]);
    }

    public function create(): Response
    {
        return ThemeResolver::page('admin.content.form', [
            ...$this->formData(),
            'contentItem' => new ContentItem(['type' => ContentTypeEnum::MATERIAL]),
            'selectedChatIds' => [],
        ]);
    }

    public function store(
        SaveContentItemRequest $request,
        CyrillicTransliterator $transliterator,
        ContentBodySanitizer $bodySanitizer,
        ContentCoverManager $covers,
        ContentPublicationManager $publications,
        ContentTagManager $tags,
    ): RedirectResponse {
        try {
            $content = DB::transaction(function () use ($request, $transliterator, $bodySanitizer, $tags): ContentItem {
                $type = ContentTypeEnum::from($request->string('type')->toString());
                $this->assertRelatedEntityExists($type, $request->integer('related_id') ?: null);
                $publishInFeed = $type !== ContentTypeEnum::FAQ && $request->boolean('publish_in_feed');

                $content = ContentItem::query()->create([
                    ...$this->attributes($request, $type, $bodySanitizer),
                    'created_by_user_id' => $request->user()->id,
                    'updated_by_user_id' => $request->user()->id,
                    'alias' => $this->uniqueAlias($request->string('title')->toString(), $transliterator),
                    'feed_published_at' => $publishInFeed ? now() : null,
                ]);

                $tags->sync($content, $request->string('tags')->toString());

                return $content;
            });

            if ($request->hasFile('cover')) {
                $covers->replace($content, (string) $request->file('cover')->get());
            }

            $publications->syncTelegramChats($content, $this->telegramChatIds($request, $content));

            return redirect()
                ->route('admin.content.edit', $content->alias)
                ->with('status', 'Материал создан.');
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors([
                'content' => 'Не удалось сохранить материал. Попробуйте ещё раз.',
            ]);
        }
    }

    public function edit(ContentItem $contentItem): Response
    {
        $contentItem->load(['cover', 'inlineImages', 'tags', 'telegramPublications']);

        return ThemeResolver::page('admin.content.form', [
            ...$this->formData(),
            'contentItem' => $contentItem,
            'selectedChatIds' => $contentItem->telegramPublications
                ->where('is_enabled', true)
                ->pluck('chat_id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
        ]);
    }

    public function update(
        SaveContentItemRequest $request,
        ContentItem $contentItem,
        ContentBodySanitizer $bodySanitizer,
        ContentCoverManager $covers,
        ContentPublicationManager $publications,
        ContentTagManager $tags,
    ): RedirectResponse {
        try {
            DB::transaction(function () use ($request, $contentItem, $bodySanitizer, $tags): void {
                $type = ContentTypeEnum::from($request->string('type')->toString());
                $this->assertRelatedEntityExists($type, $request->integer('related_id') ?: null);
                $wasPublished = $contentItem->feed_published_at;
                $publishInFeed = $type !== ContentTypeEnum::FAQ && $request->boolean('publish_in_feed');

                $contentItem->update([
                    ...$this->attributes($request, $type, $bodySanitizer),
                    'updated_by_user_id' => $request->user()->id,
                    'feed_published_at' => $publishInFeed ? ($wasPublished ?? now()) : null,
                ]);

                $tags->sync($contentItem, $request->string('tags')->toString());
            });

            if ($request->hasFile('cover')) {
                $covers->replace($contentItem, (string) $request->file('cover')->get());
            }

            $publications->syncTelegramChats($contentItem->fresh(), $this->telegramChatIds($request, $contentItem));

            return back()->with('status', 'Материал сохранён.');
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors([
                'content' => 'Не удалось сохранить материал. Попробуйте ещё раз.',
            ]);
        }
    }

    public function storeInlineImage(
        StoreContentInlineImageRequest $request,
        ContentItem $contentItem,
        ContentInlineImageManager $images,
    ): RedirectResponse {
        try {
            $images->store(
                $contentItem,
                (string) $request->file('image')->get(),
                $request->filled('title') ? $request->string('title')->toString() : null,
                $request->filled('description') ? $request->string('description')->toString() : null,
            );

            return back()->with('status', 'Изображение добавлено. Используйте его shortcode в полном описании.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['image' => 'Не удалось загрузить изображение.']);
        }
    }

    public function destroyInlineImage(
        Request $request,
        ContentItem $contentItem,
        Media $media,
        ContentInlineImageManager $images,
    ): RedirectResponse {
        abort_unless($request->user()?->can('manage-content'), 403);
        $images->delete($contentItem, $media);

        return back()->with('status', 'Изображение удалено. Проверьте, что его shortcode больше не используется в тексте.');
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'types' => ContentTypeEnum::cases(),
            'formats' => ContentFormatEnum::cases(),
            'telegramChats' => TelegramChat::query()
                ->where('is_active', true)
                ->orderBy('title')
                ->get(),
            'relatedEntities' => [
                ContentTypeEnum::EVENT->value => Event::query()
                    ->orderByDesc('starts_at')
                    ->limit(200)
                    ->get(['id', 'title'])
                    ->map(fn (Event $event): array => ['id' => $event->id, 'label' => $event->title])
                    ->all(),
                ContentTypeEnum::VENUE->value => Venue::query()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Venue $venue): array => ['id' => $venue->id, 'label' => $venue->name])
                    ->all(),
                ContentTypeEnum::USER->value => User::query()
                    ->with('profile')
                    ->orderBy('username')
                    ->limit(500)
                    ->get()
                    ->map(function (User $user): array {
                        $name = trim(implode(' ', array_filter([
                            $user->profile?->first_name,
                            $user->profile?->last_name,
                        ]))) ?: $user->username;

                        return [
                            'id' => $user->id,
                            'label' => $name.' · #'.$user->id,
                        ];
                    })
                    ->all(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function attributes(
        SaveContentItemRequest $request,
        ContentTypeEnum $type,
        ContentBodySanitizer $bodySanitizer,
    ): array {
        $format = ContentFormatEnum::from($request->string('content_format')->toString());
        $body = trim($request->string('full_description')->toString());
        $isFaq = $type === ContentTypeEnum::FAQ;

        return [
            'type' => $type,
            'title' => trim($request->string('title')->toString()),
            'short_description' => trim($request->string('short_description')->toString()),
            'full_description' => $format === ContentFormatEnum::SAFE_HTML
                ? $bodySanitizer->sanitize($body)
                : $body,
            'content_format' => $format,
            'link_url' => $request->filled('link_url') ? trim($request->string('link_url')->toString()) : null,
            'meta_title' => $request->filled('meta_title') ? trim($request->string('meta_title')->toString()) : null,
            'meta_description' => $request->filled('meta_description')
                ? trim($request->string('meta_description')->toString())
                : null,
            'meta_keywords' => $request->filled('meta_keywords')
                ? trim($request->string('meta_keywords')->toString())
                : null,
            'related_type' => ! $isFaq && $type->supportsRelatedEntity() && $request->filled('related_id') ? $type->value : null,
            'related_id' => ! $isFaq && $type->supportsRelatedEntity() && $request->filled('related_id')
                ? $request->integer('related_id')
                : null,
            'publish_in_feed' => ! $isFaq && $request->boolean('publish_in_feed'),
            'publish_in_telegram' => ! $isFaq && $request->boolean('publish_in_telegram'),
        ];
    }

    private function uniqueAlias(string $title, CyrillicTransliterator $transliterator): string
    {
        $base = Str::slug($transliterator->transliterate($title)) ?: 'material';
        $alias = $base;
        $suffix = 2;

        while (ContentItem::withTrashed()->where('alias', $alias)->exists()) {
            $alias = $base.'-'.$suffix++;
        }

        return $alias;
    }

    private function assertRelatedEntityExists(ContentTypeEnum $type, ?int $relatedId): void
    {
        if (! $type->supportsRelatedEntity() || $relatedId === null) {
            return;
        }

        $exists = match ($type) {
            ContentTypeEnum::EVENT => Event::query()->whereKey($relatedId)->exists(),
            ContentTypeEnum::VENUE => Venue::query()->whereKey($relatedId)->exists(),
            ContentTypeEnum::USER => User::query()->whereKey($relatedId)->exists(),
            ContentTypeEnum::MATERIAL, ContentTypeEnum::FAQ => true,
        };

        if (! $exists) {
            throw ValidationException::withMessages([
                'related_id' => 'Связанная сущность не найдена.',
            ]);
        }
    }

    /** @return array<int, mixed> */
    private function telegramChatIds(SaveContentItemRequest $request, ContentItem $content): array
    {
        return $content->type === ContentTypeEnum::FAQ
            ? []
            : (array) $request->input('telegram_chat_ids', []);
    }
}
