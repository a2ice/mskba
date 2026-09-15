<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Media\Domain\Models\Media;
use Illuminate\Support\Str;

final class ContentShortcodeRenderer
{
    /** @var array<string, string> */
    private array $replacements = [];

    public function __construct(private readonly EmbeddedEntityShortcodeRenderer $entities) {}

    public function extract(ContentItem $content, string $source): string
    {
        $this->replacements = [];
        $source = $this->entities->extract($source);
        $source = $this->decodeOpenings($source);

        $source = preg_replace_callback(
            '/\[image\s+id=["\']?(\d+)["\']?(?:\s+view=["\']?(default|wide)["\']?)?\s*\]/iu',
            fn (array $matches): string => $this->token($this->imageHtml(
                $content,
                (int) $matches[1],
                strtolower((string) ($matches[2] ?? 'default')),
            )),
            $source,
        ) ?? $source;

        $source = preg_replace_callback(
            '/\[creation_requirements\s+topic=["\']?([a-z0-9_-]+)["\']?\s*\]/iu',
            fn (array $matches): string => $this->token($this->creationRequirementsHtml((string) $matches[1])),
            $source,
        ) ?? $source;

        $source = preg_replace_callback(
            '/\[anchor\s+id=["\']?([a-z0-9][a-z0-9_-]{0,63})["\']?\s*\]/iu',
            fn (array $matches): string => $this->token(sprintf(
                '<span class="content-anchor" id="%s" aria-hidden="true"></span>',
                e(strtolower((string) $matches[1])),
            )),
            $source,
        ) ?? $source;

        return $source;
    }

    public function restore(string $rendered): string
    {
        return $this->entities->restore(strtr($rendered, $this->replacements));
    }

    private function decodeOpenings(string $source): string
    {
        return preg_replace_callback(
            '/\[(?:image|creation_requirements|anchor)\b[^\]]*\]/iu',
            static fn (array $matches): string => html_entity_decode(
                $matches[0],
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            ),
            $source,
        ) ?? $source;
    }

    private function token(string $replacement): string
    {
        $token = 'MSKBACONTENT'.Str::upper(Str::random(24)).'TOKEN';
        $this->replacements[$token] = $replacement;

        return $token;
    }

    private function imageHtml(ContentItem $content, int $mediaId, string $view): string
    {
        $media = Media::query()
            ->whereKey($mediaId)
            ->where('mediable_type', ContentItem::class)
            ->where('mediable_id', $content->id)
            ->where('collection', ContentInlineImageManager::COLLECTION)
            ->first();

        if (! $media) {
            return '';
        }

        $classes = $view === 'wide' ? 'content-image content-image--wide' : 'content-image';
        $alt = trim((string) $media->title);
        $caption = trim((string) $media->description);

        return sprintf(
            '<figure class="%s"><img src="%s" alt="%s" loading="lazy">%s</figure>',
            e($classes),
            e($media->publicUrl()),
            e($alt),
            $caption !== '' ? '<figcaption>'.e($caption).'</figcaption>' : '',
        );
    }

    private function creationRequirementsHtml(string $topic): string
    {
        $requirements = config('creation-guides.'.$topic.'.requirements');

        if (! is_array($requirements) || $requirements === []) {
            return '';
        }

        $items = collect($requirements)->map(function (array $requirement): string {
            $links = collect($requirement['links'] ?? [])->map(function (array $link): string {
                if (! isset($link['route'], $link['label'])) {
                    return '';
                }

                $url = route((string) $link['route']);
                if (! empty($link['anchor'])) {
                    $url .= '#'.ltrim((string) $link['anchor'], '#');
                }

                return sprintf(
                    '<a href="%s" target="_blank" rel="noopener">%s</a>',
                    e($url),
                    e((string) $link['label']),
                );
            })->filter()->implode(' · ');

            return '<li>'.e((string) ($requirement['text'] ?? '')).($links !== '' ? ' '.$links : '').'</li>';
        })->implode('');

        return '<aside class="content-requirements"><strong>Условия создания</strong><ul>'.$items.'</ul></aside>';
    }
}
