<?php

namespace Database\Seeders;

use App\Modules\Content\Domain\Enums\ContentFormatEnum;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Seeder;

/**
 * Production-safe initial copy. Never overwrites editorial changes, restores
 * soft-deleted content, or changes the publication state of existing articles.
 */
final class PageFaqContentSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::query()
            ->where('system_role', UserSystemRoleEnum::SUPERADMIN)
            ->orderBy('id')
            ->first() ?? User::query()->orderBy('id')->first();

        if (! $owner) {
            return;
        }

        foreach (config('faq-page-types', []) as $key => $page) {
            $features = collect($page['features'] ?? [])
                ->map(fn (string $feature): string => '<li>'.e($feature).'</li>')
                ->implode('');

            $related = collect($page['related'] ?? [])
                ->map(function (string $relatedKey): string {
                    $linked = config('faq-page-types.'.$relatedKey);
                    if (! is_array($linked)) {
                        return '';
                    }
                    $alias = 'faq-page-'.str_replace('.', '-', $relatedKey);
                    return '<li><a href="/faq/'.e($alias).'">'.e($linked['title']).'</a></li>';
                })
                ->implode('');

            $html = '<h2>Возможности</h2><ul>'.$features.'</ul>'
                .'<h2>Связанные материалы</h2><ul>'.$related.'</ul>';

            ContentItem::withTrashed()->firstOrCreate(
                ['system_key' => 'faq.page.'.$key],
                [
                    'created_by_user_id' => $owner->id,
                    'updated_by_user_id' => $owner->id,
                    'type' => ContentTypeEnum::FAQ,
                    'title' => $page['title'],
                    'alias' => 'faq-page-'.str_replace('.', '-', $key),
                    'short_description' => $page['about'],
                    'full_description' => $html,
                    'content_format' => ContentFormatEnum::SAFE_HTML,
                    'publish_in_feed' => false,
                    'publish_in_telegram' => false,
                ],
            );
        }
    }
}
