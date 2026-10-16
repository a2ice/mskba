<?php

namespace App\Modules\Content\Application\Services;

use Illuminate\Validation\ValidationException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/** Strict content-only HTML and scoped CSS. Never execute stored PHP/Blade/JS. */
final class PageBuilderMarkup
{
    private HtmlSanitizer $html;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig)
            ->withMaxInputLength(100_000)
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowMediaSchemes(['http', 'https']);
        foreach (['section', 'article', 'header', 'footer', 'main', 'div', 'span',
            'h1', 'h2', 'h3', 'h4', 'p', 'strong', 'em', 'ul', 'ol', 'li',
            'figure', 'figcaption', 'blockquote', 'small', 'nav'] as $tag) {
            $config = $config->allowElement($tag, ['class', 'id']);
        }
        $config = $config->allowElement('a', ['href', 'title', 'class'])
            ->allowElement('img', ['src', 'alt', 'width', 'height', 'class', 'loading'])
            ->allowElement('br')
            ->allowElement('hr')
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->forceAttribute('img', 'loading', 'lazy');
        $this->html = new HtmlSanitizer($config);
    }

    public function validate(string $html, ?string $css): void
    {
        if (strlen($html) > 100_000
            || preg_match('~<(?:script|iframe|style|form|object|embed|svg|video|audio|link|meta)\b|<\?|@php\b|\{!!|(?<!\[)\{\{~i', $html)
            || preg_match('/\son[a-z]+\s*=|javascript\s*:|data\s*:/i', $html)) {
            throw ValidationException::withMessages(['draft_html' => 'Запрещены исполняемый код, iframe, обработчики событий и Blade.']);
        }
        $this->css($css);
    }

    public function html(string $html): string
    {
        return $this->html->sanitize($html);
    }

    /** Restrict selectors to the builder root and allow only inert declarations. */
    public function css(?string $css): string
    {
        $css = trim((string) $css);
        if ($css === '') {
            return '';
        }
        if (strlen($css) > 15_000 || preg_match('~[@{}<>\\\\]|url\s*\(|expression\s*\(|(?:javascript|data|behavior|binding)\s*:|/\*~i', str_replace(['{', '}'], '', $css))) {
            throw ValidationException::withMessages(['draft_css' => 'Запрещены импорты, URL, скрипты и глобальные CSS-правила.']);
        }
        // Explicitly parse a subset of CSS: selectors plus ordinary properties.
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $blocks, PREG_SET_ORDER);
        if ($blocks === [] || trim(preg_replace('/[^{}]+\{[^{}]*\}/s', '', $css)) !== '') {
            throw ValidationException::withMessages(['draft_css' => 'Используй простые CSS-правила selector { property: value; }.']);
        }

        $allowed = ['color','background','background-color','border','border-color','border-radius',
            'box-shadow','display','gap','row-gap','column-gap','padding','padding-top','padding-bottom',
            'padding-left','padding-right','margin','margin-top','margin-bottom','margin-left','margin-right',
            'font-size','font-weight','font-family','line-height','letter-spacing','text-align',
            'width','max-width','min-width','height','min-height','object-fit','overflow',
            'align-items','justify-content','grid-template-columns','flex-direction','flex-wrap','opacity'];
        $output = [];
        foreach ($blocks as $block) {
            $selectors = array_map('trim', explode(',', trim($block[1])));
            if (count($selectors) > 8) {
                throw ValidationException::withMessages(['draft_css' => 'Слишком много селекторов в одном правиле.']);
            }
            foreach ($selectors as $selector) {
                if (! preg_match('/\A(?:[.#]?[a-zA-Z][a-zA-Z0-9_-]*(?::(?:hover|focus-visible|active))?)(?:\s+[.#]?[a-zA-Z][a-zA-Z0-9_-]*)*\z/D', $selector)) {
                    throw ValidationException::withMessages(['draft_css' => 'Разрешены только локальные классы, идентификаторы и простые селекторы.']);
                }
            }
            $declarations = [];
            foreach (explode(';', $block[2]) as $declaration) {
                if (trim($declaration) === '') {
                    continue;
                }
                if (! preg_match('/\A\s*([a-z-]+)\s*:\s*(.*?)\s*\z/sD', $declaration, $parts)
                    || ! in_array($parts[1], $allowed, true)
                    || ! preg_match('/\A[a-zA-Z0-9#.,% ()_+\-*\/]+\z/D', $parts[2])
                    || preg_match('/url\s*\(|expression\s*\(|javascript|data:|behavior|binding|\/\*/i', $parts[2])) {
                    throw ValidationException::withMessages(['draft_css' => 'Недопустимое CSS-свойство или значение.']);
                }
                $declarations[] = $parts[1].': '.$parts[2];
            }
            if ($declarations !== []) {
                $output[] = implode(', ', array_map(fn (string $x): string => '.mskba-page-builder '.$x, $selectors))
                    .' { '.implode('; ', $declarations).'; }';
            }
        }
        return implode("\n", $output);
    }
}
