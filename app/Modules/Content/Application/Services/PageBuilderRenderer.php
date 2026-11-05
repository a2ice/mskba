<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Content\Domain\Models\PageBuilderTemplate;
use App\Presentation\Theming\ThemeResolver;
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
        private readonly ThemeResolver $themeResolver,
    ) {}

    /** @return array{html:string,css:string,originalCss:string,scripts:list<string>,lqip:array<string,string>} */
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
        // Expand placeholders in both root markup and reusable components.
        $usedComponents = [];
        $html = $this->renderComponents($raw, $preview, $usedComponents);
        // Hero Slider's editor-owned baseline replaces the old Vite import.
        // Keep its cascade position BEFORE template/page CSS so page-specific
        // layout overrides continue to win, just as they did with static CSS.
        $hero = $usedComponents['hero-slider'] ?? null;
        $css = '';
        if ($hero !== null && ($preview || $hero->published_at !== null)) {
            $css = $this->markup->css($preview ? $hero->draft_css : $hero->published_css);
        }
        $css .= "\n".$this->markup->css($templateCss)."\n".$this->markup->css($pageCss);
        foreach ($usedComponents as $component) {
            if ($component->slug === 'hero-slider') {
                continue;
            }
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
        $originalCss = trim($css);
        // mskba_dark currently has no Page Builder @stack('scripts') and
        // cannot hydrate URL previews. Leave its original media unchanged.
        if ($this->themeResolver->active() === 'mskba_app') {
            $html = $this->firstPaintLqip->apply($html, $previews);
            $css = $this->firstPaintLqip->css($css, $previews);
        }

        return [
            'html' => $html,
            'css' => trim($css),
            'originalCss' => $originalCss,
            'scripts' => $preview ? [] : $this->scripts->forOwners($owners),
            'lqip' => $previews,
        ];
    }
    /**
     * Sanitize each authored fragment independently, replacing nested component
     * placeholders only with already-sanitized server-owned HTML.
     *
     * @param array<string,PageBuilderComponent> $usedComponents
     * @param list<string> $ancestors
     */
    private function renderComponents(
        string $source,
        bool $preview,
        array &$usedComponents,
        array $ancestors = [],
        int $depth = 0,
    ): string {
        $matches = PageBuilderComponentTags::find($source);
        $slugs = [];
        foreach ($matches as $match) {
            if (preg_match('/\bname="([a-z][a-z0-9_-]{1,79})"/', $match['attrs'], $found)) {
                $slugs[] = $found[1];
            }
        }
        $components = $slugs === []
            ? collect()
            : PageBuilderComponent::query()->whereIn('slug', array_unique($slugs))->get()->keyBy('slug');

        $replacements = [];
        $withTokens = preg_replace_callback(
            PageBuilderComponentTags::PATTERN,
            function (array $match) use (&$replacements, &$usedComponents, $components, $preview, $ancestors, $depth): string {
                $attributes = [];
                preg_match_all('/\b([a-z-]+)="([^"]*)"/', $match['attrs'], $found, PREG_SET_ORDER);
                foreach ($found as $attr) {
                    $attributes[$attr[1]] = $attr[2];
                }

                $token = 'MSKBACOMPONENTTOKEN'.count($replacements).'END';
                $name = (string) ($attributes['name'] ?? '');
                $source = (string) ($attributes['source'] ?? '');
                $limit = is_numeric($attributes['limit'] ?? null)
                    ? max(1, min(12, (int) $attributes['limit'])) : 5;

                if ($name === 'games-list' && $this->sources->isRegistered($source)) {
                    $url = route('page-builder.data', ['source' => $source, 'limit' => $limit]);
                    $replacements[$token] = '<div class="mskba-builder-dynamic" data-mskba-builder-source="'
                        .e($source).'" data-source-url="'.e($url).'">'
                        .'<div class="mskba-builder-shimmer" aria-label="Загружаем список">'
                        .'<span></span><span></span><span></span></div></div>';
                    return $token;
                }

                /** @var PageBuilderComponent|null $component */
                $component = $components->get($name);
                if ($component === null || (! $preview && $component->published_at === null)) {
                    $replacements[$token] = $preview
                        ? '<p class="mskba-builder-unavailable">Неизвестный или неопубликованный компонент: '.e($name).'</p>'
                        : '';
                    return $token;
                }

                // Prevent user-authored A → B → A and self-reference cycles.
                if ($depth >= 8 || in_array($name, $ancestors, true)) {
                    $replacements[$token] = $preview
                        ? '<p class="mskba-builder-unavailable">Циклическая или слишком глубокая вложенность: '.e($name).'</p>'
                        : '';
                    return $token;
                }

                $usedComponents[$name] = $component;
                if ($name === 'hero-slider') {
                    $replacements[$token] = $this->heroSlider->render(
                        $component, $preview, $this->markup->html(trim($match['slot'])),
                    );
                    return $token;
                }

                $body = (string) ($preview ? $component->draft_html : $component->published_html);
                $replacements[$token] = $this->renderComponents(
                    $body, $preview, $usedComponents, [...$ancestors, $name], $depth + 1,
                );
                return $token;
            },
            $source,
        );

        return strtr($this->markup->html((string) $withTokens), $replacements);
    }

}
