<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Content\Domain\Models\PageBuilderTemplate;
use Illuminate\Support\Collection;

final class PageBuilderRenderer
{
    public function __construct(
        private readonly PageBuilderMarkup $markup,
        private readonly PageBuilderSources $sources,
        private readonly HeroSliderComponent $heroSlider,
        private readonly PageBuilderScriptUrls $scripts,
        private readonly PageBuilderLqipManifest $lqipManifest,
        private readonly PageBuilderFirstPaintLqip $firstPaintLqip,
    ) {}

    /** @return array{html:string,css:string,scripts:list<string>,lqip:array<string,string>} */
    public function render(PageBuilderPage $page, bool $preview = false): array
    {
        $pageHtml = (string) ($preview ? $page->draft_html : $page->published_html);
        $pageCss = $preview ? $page->draft_css : $page->published_css;
        $this->markup->validate($pageHtml, $pageCss);

        $template = $preview ? $page->template : $page->publishedTemplate;
        $templateHtml = '[[page_content]]';
        $templateCss = '';
        if ($template instanceof PageBuilderTemplate) {
            $templateHtml = (string) ($preview ? $template->draft_html : $template->published_html);
            $templateCss = (string) ($preview ? $template->draft_css : $template->published_css);
        }
        $this->markup->validate($templateHtml, $templateCss);

        // Always process components BEFORE the HTML sanitizer. Inject only
        // server-controlled, separately sanitized HTML after the sanitizer.
        $raw = str_replace('[[page_content]]', $pageHtml, $templateHtml);
        $matches = [];
        preg_match_all('~<mskba-component\b[^>]*>\s*</mskba-component>~i', $raw, $matches);
        $slugs = [];
        foreach ($matches[0] ?? [] as $tag) {
            if (preg_match('/\bname="([a-z][a-z0-9_-]{1,79})"/', $tag, $m)) {
                $slugs[] = $m[1];
            }
        }
        /** @var Collection<string,PageBuilderComponent> $components */
        $components = PageBuilderComponent::query()->whereIn('slug', array_unique($slugs))
            ->get()->keyBy('slug');

        $replacements = [];
        $htmlWithTokens = preg_replace_callback(
            '~<mskba-component\b(?P<attrs>[^>]*)>\s*</mskba-component>~i',
            function (array $match) use (&$replacements, $components, $preview): string {
                $attributes = [];
                preg_match_all('/\b([a-z-]+)="([^"]*)"/', $match['attrs'], $found, PREG_SET_ORDER);
                foreach ($found as $attr) {
                    $attributes[$attr[1]] = $attr[2];
                }
                $token = 'MSKBACOMPONENTTOKEN'.count($replacements).'END';
                $name = (string) ($attributes['name'] ?? '');
                $source = (string) ($attributes['source'] ?? '');
                $limit = is_numeric($attributes['limit'] ?? null) ? max(1, min(12, (int) $attributes['limit'])) : 5;
                if ($name === 'games-list' && $this->sources->isRegistered($source)) {
                    $url = route('page-builder.data', ['source' => $source, 'limit' => $limit]);
                    $replacements[$token] = '<div class="mskba-builder-dynamic" data-mskba-builder-source="'
                        .e($source).'" data-source-url="'.e($url).'">'
                        .'<div class="mskba-builder-shimmer" aria-label="Загружаем список">'
                        .'<span></span><span></span><span></span></div></div>';
                } else {
                    /** @var PageBuilderComponent|null $component */
                    $component = $components->get($name);
                    if ($name === 'hero-slider') {
                        $replacements[$token] = $component && ($preview || $component->published_at !== null)
                            ? $this->heroSlider->render($component, $preview)
                            : ($preview ? '<p class="mskba-builder-unavailable">Hero Slider пока не создан или не опубликован.</p>' : '');
                    } else {
                        $body = $component
                            ? ($preview ? $component->draft_html : $component->published_html)
                            : null;
                        $replacements[$token] = is_string($body)
                            ? $this->markup->html($body)
                            : ($preview ? '<p class="mskba-builder-unavailable">Неизвестный или неопубликованный компонент: '.e($name).'</p>' : '');
                    }
                }
                return $token;
            },
            $raw,
        );
        $html = strtr($this->markup->html((string) $htmlWithTokens), $replacements);
        $css = $this->markup->css($templateCss)."\n".$this->markup->css($pageCss);
        foreach ($components as $component) {
            $body = $preview ? $component->draft_html : $component->published_html;
            if ($body !== null) {
                $css .= "\n".$this->markup->css($preview ? $component->draft_css : $component->published_css);
            }
        }
        // Never execute draft JS in the normal authenticated page preview.
        // Only the separately published and enabled resources are served.
        $owners = [$page];
        if ($template instanceof PageBuilderTemplate) {
            $owners[] = $template;
        }
        foreach ($components as $component) {
            if (! $preview && $component->published_at === null) {
                continue;
            }
            $owners[] = $component;
        }

        $previews = $this->lqipManifest->forContent($html, $css);
        $html = $this->firstPaintLqip->apply($html, $previews);

        return [
            'html' => $html,
            'css' => trim($css),
            'scripts' => $preview ? [] : $this->scripts->forOwners($owners),
            'lqip' => $previews,
        ];
    }
}
