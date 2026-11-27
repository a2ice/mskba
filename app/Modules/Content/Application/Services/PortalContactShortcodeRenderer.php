<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Portal\Application\Services\PortalContactSettings;
use DOMDocument;
use DOMElement;
use DOMText;
use DOMXPath;

final readonly class PortalContactShortcodeRenderer
{
    private const PATTERN = '/\[portal-contact-email(?: (partnership))?\]/';

    public function __construct(private PortalContactSettings $contacts) {}

    /** Expand only text nodes in already sanitized/rendered content, never attributes. */
    public function render(string $html): string
    {
        if (! preg_match(self::PATTERN, $html)) {
            return $html;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            return $html;
        }

        $root = $document->getElementsByTagName('div')->item(0);
        if (! $root instanceof DOMElement) {
            return $html;
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('.//text()[not(ancestor::a or ancestor::pre or ancestor::code or ancestor::script or ancestor::style or ancestor::textarea)]', $root);
        if ($nodes === false) {
            return $html;
        }

        $email = $this->contacts->publicEmail();
        foreach (iterator_to_array($nodes) as $node) {
            if (! $node instanceof DOMText || ! preg_match_all(self::PATTERN, $node->data, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $fragment = $document->createDocumentFragment();
            $offset = 0;
            foreach ($matches as $match) {
                [$marker, $start] = $match[0];
                $fragment->appendChild($document->createTextNode(substr($node->data, $offset, $start - $offset)));
                $link = $document->createElement('a');
                if ($email !== null) {
                    $subject = ($match[1][0] ?? '') === 'partnership'
                        ? '?subject='.rawurlencode('MSKBA - сотрудничество') : '';
                    $link->setAttribute('href', $email['href'].$subject);
                    $link->appendChild($document->createTextNode($email['value']));
                    $fragment->appendChild($document->createTextNode('на '));
                } else {
                    $link->setAttribute('href', route('faq.index'));
                    $link->appendChild($document->createTextNode('раздел помощи'));
                    $fragment->appendChild($document->createTextNode('через '));
                }
                $link->setAttribute('rel', 'noopener noreferrer');
                $fragment->appendChild($link);
                $offset = $start + strlen($marker);
            }
            $fragment->appendChild($document->createTextNode(substr($node->data, $offset)));
            $node->parentNode->replaceChild($fragment, $node);
        }

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return $result;
    }
}
