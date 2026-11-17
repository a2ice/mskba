<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Content\Domain\Models\FaqSection;
use Illuminate\Support\Str;

final class FaqHelpCatalog
{
    public function __construct(private readonly ContentBodyRenderer $renderer) {}

    /** @return array<string, mixed> */
    public function content(?string $context, ?string $section, ?string $article): array
    {
        $systemSections = config('support.faq_system_sections', []);
        $items = ContentItem::query()->published()->where('type', ContentTypeEnum::FAQ)
            ->with(['tags', 'inlineImages', 'faqSections'])->orderBy('title')->get();
        $groups = [];
        foreach (FaqSection::query()->orderBy('sort_order')->orderBy('id')->get() as $faqSection) {
            $groups[$faqSection->code] = [
                'key' => $faqSection->code,
                'label' => $faqSection->name,
                'articles' => [],
            ];
        }

        foreach ($items as $item) {
            $selectedSections = $item->faqSections->pluck('code')->all();
            $sectionCodes = $selectedSections ?: $item->tags->pluck('normalized_name')->all();

            if (isset($systemSections[$item->system_key])) {
                $sectionCodes[] = $systemSections[$item->system_key];
            }

            $matchingSections = array_intersect(array_keys($groups), $sectionCodes);
            if ($matchingSections === [] && isset($groups['other'])) {
                $matchingSections = ['other'];
            }

            foreach ($matchingSections as $code) {
                $groups[$code]['articles'][] = $this->summary($item);
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
