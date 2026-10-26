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
        foreach (['section', 'article', 'aside', 'header', 'footer', 'main', 'div', 'span',
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

    /**
     * Preserve standard CSS properties, selectors, nesting, media/container
     * queries, keyframes and custom properties. Browser parsing is the source
     * of truth for declaration validity, not a hand-maintained allowlist.
     *
     * @scope keeps editor CSS inside the Page Builder canvas; preventing
     * unmatched braces is essential to stop authored rules escaping it.
     * Reject raw HTML tag termination while the CSS is emitted in <style>.
     */
    public function css(?string $css): string
    {
        $css = trim((string) $css);
        if ($css === '') {
            return '';
        }
        if (strlen($css) > 100_000
            || preg_match('~</\s*style\b|<\s*script\b|<\?php|<!--|-->~i', $css)
            || preg_match('~@(?:import|charset|namespace)\b|expression\s*\(|-moz-binding\b|(?:javascript|data)\s*:~i', $css)) {
            throw ValidationException::withMessages([
                'draft_css' => 'Запрещены исполняемые конструкции, @import и выход CSS за пределы редактора.',
            ]);
        }

        // Balanced braces must be checked while ignoring quoted strings and
        // comments. Otherwise a stray } can escape the page-only @scope.
        $depth = 0;
        $quote = null;
        $comment = false;
        $length = strlen($css);
        for ($index = 0; $index < $length; $index++) {
            $char = $css[$index];
            $next = $css[$index + 1] ?? '';
            if ($comment) {
                if ($char === '*' && $next === '/') {
                    $comment = false;
                    $index++;
                }
                continue;
            }
            if ($quote !== null) {
                if ($char === '\\') {
                    $index++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '/' && $next === '*') {
                $comment = true;
                $index++;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth < 0) {
                    break;
                }
            }
        }
        if ($depth !== 0 || $quote !== null || $comment) {
            throw ValidationException::withMessages([
                'draft_css' => 'Проверь закрывающие скобки, кавычки и комментарии в CSS.',
            ]);
        }

        // Native CSS scoping supports modern nested @media/@container and
        // pseudo-selectors without rewriting author-controlled declarations.
        return "@scope (.mskba-page-builder) {\n".$css."\n}";
    }
}
