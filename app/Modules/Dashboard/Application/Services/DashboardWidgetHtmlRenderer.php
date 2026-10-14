<?php

namespace App\Modules\Dashboard\Application\Services;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Rich content is stored as admin-authored HTML, but always sanitized on render.
 * This content is NOT the separate sandboxed custom_html widget type.
 */
final class DashboardWidgetHtmlRenderer
{
    public function render(?string $html, array $placeholders = []): string
    {
        if (blank($html)) {
            return '';
        }

        $config = (new HtmlSanitizerConfig())
            ->allowSafeElements()
            ->allowElement('a', ['href', 'title', 'class', 'target'])
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
            ->allowRelativeLinks()
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->dropAttribute('style', '*');

        $safe = (new HtmlSanitizer($config))->sanitize($html);

        // Only allow project-defined button classes; other arbitrary classes could
        // trigger CSS of unrelated portal components or spoof the page UI.
        $safe = preg_replace_callback('/\sclass="([^"]*)"/u', static function (array $matches): string {
            $classes = preg_split('/\s+/', html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $allowed = array_values(array_unique(array_intersect($classes ?: [], ['button', 'primary', 'secondary'])));
            return $allowed === [] ? '' : ' class="'.implode(' ', $allowed).'"';
        }, $safe) ?? '';

        // Replacements happen AFTER sanitizing stored markup and are always escaped.
        // No Blade evaluation, arbitrary variables, or template execution.
        foreach ($placeholders as $name => $value) {
            $safe = str_replace('{{ '.$name.' }}', e((string) $value), $safe);
        }

        return $safe;
    }
}
