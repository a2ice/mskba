<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Enums\ContentFormatEnum;
use App\Modules\Content\Domain\Enums\ContentStatusEnum;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Content\Domain\Models\ProjectUpdateDetail;
use App\Modules\Identity\Domain\Models\User;
use App\Support\Text\CyrillicTransliterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Internal draft ingest uses the existing ContentItem and writes private data
 * only to project_update_details. It can never publish content to any channel.
 */
final class ProjectUpdateDrafts
{
    public function __construct(private readonly CyrillicTransliterator $transliterator) {}

    /** @param array<string, mixed> $data */
    public function upsert(array $data, User $actor): ContentItem
    {
        return DB::transaction(function () use ($data, $actor): ContentItem {
            $detail = ProjectUpdateDetail::query()->where('source_key', $data['source_key'])
                ->lockForUpdate()->first();

            if ($detail !== null) {
                $content = ContentItem::query()->lockForUpdate()->findOrFail($detail->content_item_id);
                if ($content->type !== ContentTypeEnum::SYSTEM_UPDATE
                    || $content->status !== ContentStatusEnum::DRAFT) {
                    throw new HttpException(409, 'Опубликованную или архивную запись нельзя заменить через API.');
                }
            } else {
                $content = new ContentItem;
                $content->alias = $this->uniqueAlias((string) $data['title']);
                $content->created_by_user_id = $actor->id;
                $content->type = ContentTypeEnum::SYSTEM_UPDATE;
            }

            $content->fill([
                'updated_by_user_id' => $actor->id,
                'title' => trim((string) $data['title']),
                'short_description' => trim((string) $data['summary']),
                'full_description' => trim((string) $data['content']),
                'content_format' => ContentFormatEnum::MARKDOWN,
                'status' => ContentStatusEnum::DRAFT,
                'publish_in_feed' => false,
                'publish_in_telegram' => false,
                'feed_published_at' => null,
                'link_url' => null,
                'meta_title' => null,
                'meta_description' => null,
                'meta_keywords' => null,
            ]);
            $content->save();

            // Preserve verification only if the evidence has not changed.
            $evidence = [
                'pr_url' => $data['github_pr'],
                'documentation_paths' => array_values($data['documentation']),
                'task_reference' => $data['task_reference'] ?? null,
            ];
            $changed = $detail !== null
                && ($detail->pr_url !== $evidence['pr_url']
                    || $detail->documentation_paths !== $evidence['documentation_paths']
                    || $detail->task_reference !== $evidence['task_reference']);

            ProjectUpdateDetail::query()->updateOrCreate(
                ['content_item_id' => $content->id],
                [
                    ...$evidence,
                    'source_key' => $data['source_key'],
                    'source' => 'agent-api',
                    'production_verified_at' => $changed ? null : $detail?->production_verified_at,
                    'production_verified_by_user_id' => $changed ? null : $detail?->production_verified_by_user_id,
                ],
            );

            return $content->refresh();
        }, 3);
    }

    private function uniqueAlias(string $title): string
    {
        $base = rtrim(substr(Str::slug($this->transliterator->transliterate($title)) ?: 'system-update', 0, 230), '-');
        $alias = $base;
        $suffix = 2;
        while (in_array($alias, ContentItem::RESERVED_ALIASES, true)
            || ContentItem::withTrashed()->where('alias', $alias)->exists()) {
            $alias = $base.'-'.$suffix++;
        }

        return $alias;
    }
}
