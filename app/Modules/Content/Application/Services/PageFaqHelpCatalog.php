<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;

/**
 * Contextual help uses the same editable FAQ ContentItem as the public FAQ.
 * Nothing is exposed unless the article is published and in the whitelist.
 */
final class PageFaqHelpCatalog
{
    public function __construct(private readonly ContentBodyRenderer $renderer) {}

    /** @return array{key:string,title:string,about:string,html:string}|null */
    public function content(?string $key): ?array
    {
        $catalog = config('faq-page-types', []);
        if (! is_string($key) || ! array_key_exists($key, $catalog)) {
            return null;
        }

        $item = ContentItem::query()
            ->published()
            ->where('type', ContentTypeEnum::FAQ)
            ->where('system_key', 'faq.page.'.$key)
            ->with('inlineImages')
            ->first();

        if (! $item) {
            return null;
        }

        return [
            'key' => $key,
            'title' => (string) $item->title,
            'about' => (string) $item->short_description,
            'html' => $this->renderer->render($item),
        ];
    }
}
