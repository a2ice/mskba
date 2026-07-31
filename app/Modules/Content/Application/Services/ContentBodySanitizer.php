<?php

namespace App\Modules\Content\Application\Services;

use DOMDocument;
use DOMElement;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class ContentBodySanitizer
{
    /** @var list<string> */
    private const ALLOWED_CLASSES = [
        'content-lead',
        'content-note',
        'content-accent',
        'content-muted',
        'content-columns',
        'content-image',
        'content-image--wide',
        'content-table',
    ];

    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig)
            ->withMaxInputLength(100_000)
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
            ->allowMediaSchemes(['https', 'http'])
            ->allowElement('p', ['class'])
            ->allowElement('div', ['class'])
            ->allowElement('span', ['class'])
            ->allowElement('br')
            ->allowElement('strong', ['class'])
            ->allowElement('em', ['class'])
            ->allowElement('u', ['class'])
            ->allowElement('s', ['class'])
            ->allowElement('mark', ['class'])
            ->allowElement('h2', ['class'])
            ->allowElement('h3', ['class'])
            ->allowElement('h4', ['class'])
            ->allowElement('ul', ['class'])
            ->allowElement('ol', ['class'])
            ->allowElement('li', ['class'])
            ->allowElement('blockquote', ['class'])
            ->allowElement('pre', ['class'])
            ->allowElement('code', ['class'])
            ->allowElement('a', ['href', 'title', 'target', 'rel', 'class'])
            ->allowElement('hr')
            ->allowElement('figure', ['class'])
            ->allowElement('figcaption', ['class'])
            ->allowElement('img', [
                'src',
                'alt',
                'title',
                'width',
                'height',
                'loading',
                'class',
            ])
            ->allowElement('table', ['class'])
            ->allowElement('thead')
            ->allowElement('tbody')
            ->allowElement('tr')
            ->allowElement('th', ['colspan', 'rowspan', 'scope'])
            ->allowElement('td', ['colspan', 'rowspan'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->forceAttribute('img', 'loading', 'lazy');

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(string $html): string
    {
        return $this->filterClasses($this->sanitizer->sanitize($html));
    }

    private function filterClasses(string $html): string
    {
        if ($html === '' || ! str_contains($html, 'class=')) {
            return $html;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div data-content-root>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach ($document->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement || ! $element->hasAttribute('class')) {
                continue;
            }

            $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
            $allowed = array_values(array_intersect($classes, self::ALLOWED_CLASSES));

            if ($allowed === []) {
                $element->removeAttribute('class');
            } else {
                $element->setAttribute('class', implode(' ', $allowed));
            }
        }

        $root = $document->getElementsByTagName('div')->item(0);
        if (! $root) {
            return '';
        }

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return $result;
    }
}
