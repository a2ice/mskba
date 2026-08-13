<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Enums\ContentFormatEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use Illuminate\Support\Str;

final readonly class ContentBodyRenderer
{
    public function __construct(
        private ContentBodySanitizer $sanitizer,
        private EmbeddedEntityShortcodeRenderer $shortcodes,
    ) {}

    public function render(ContentItem $content): string
    {
        $source = $this->shortcodes->extract($content->full_description);

        if ($content->content_format === ContentFormatEnum::SAFE_HTML) {
            return $this->shortcodes->restore($this->sanitizer->sanitize($source));
        }

        return $this->shortcodes->restore(Str::markdown($source, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]));
    }

    public function renderPlainText(?string $text): string
    {
        $source = $this->shortcodes->extract((string) $text);

        return $this->shortcodes->restore(nl2br(e($source)));
    }
}
