<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Enums\ContentFormatEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use Illuminate\Support\Str;

final readonly class ContentBodyRenderer
{
    public function __construct(private ContentBodySanitizer $sanitizer) {}

    public function render(ContentItem $content): string
    {
        if ($content->content_format === ContentFormatEnum::SAFE_HTML) {
            return $this->sanitizer->sanitize($content->full_description);
        }

        return Str::markdown($content->full_description, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
}
