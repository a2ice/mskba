<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use Illuminate\Support\Str;

final class FaqHelpCatalog
{
    public function __construct(private readonly ContentBodyRenderer $renderer) {}

    /** @return array<string, mixed> */
    public function content(?string $context, ?string $section, ?string $article): array
    {
        $definitions = config('support.faq_sections', []);
        $items = ContentItem::query()->published()->where('type', ContentTypeEnum::FAQ)
            ->with(['tags', 'inlineImages'])->orderBy('title')->get();
        $groups = [];
        foreach ($definitions as $key => $definition) {
            $groups[$key] = [
                'key' => $key,
                'label' => $definition['label'],
                'articles' => [],
            ];
        }
        foreach ($items as $item) {
            $tags = $item->tags->pluck('normalized_name')->all();
            $assigned = false;
            foreach ($definitions as $key => $definition) {
                if (in_array($item->system_key, $definition['keys'] ?? [], true) || in_array($key, $tags, true)) {
                    $groups[$key]['articles'][] = $this->summary($item);
                    $assigned = true;
                }
            }
            if (! $assigned && isset($groups['other'])) {
                $groups['other']['articles'][] = $this->summary($item);
            }
        }

        $requested = $section ?: $context;
        if (! is_string($requested) || ! array_key_exists($requested, $groups)) {
            $requested = null;
        }
        $selectedArticle = null;
        if ($requested && $article) {
            $summary = collect($groups[$requested]['articles'])->firstWhere('alias', $article);
            if ($summary !== null) {
                $item = $items->firstWhere('alias', $article);
                if ($item) {
                    $selectedArticle = [...$summary, 'html' => $this->renderer->render($item)];
                }
            }
        }
        return [
            'sections' => array_values($groups),
            'section' => $requested,
            'article' => $selectedArticle,
            'breadcrumbs' => array_values(array_filter([
                ['label' => 'FAQ', 'section' => null, 'article' => null],
                $requested ? ['label' => $groups[$requested]['label'], 'section' => $requested, 'article' => null] : null,
                $selectedArticle ? ['label' => $selectedArticle['title'], 'section' => $requested, 'article' => $article] : null,
            ])),
            'empty' => $requested && !$selectedArticle && count($groups[$requested]['articles']) === 0,
        ];
    }

    /** @return array{alias:string,title:string,description:string,url:string} */
    private function summary(ContentItem $item): array
    {
        return [
            'alias' => $item->alias,
            'title' => $item->title,
            'description' => Str::limit((string) $item->short_description, 170),
            'url' => $item->publicUrl(),
        ];
    }
}
