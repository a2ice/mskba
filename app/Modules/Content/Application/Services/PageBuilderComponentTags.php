<?php

namespace App\Modules\Content\Application\Services;

use Illuminate\Validation\ValidationException;

/**
 * The single component placeholder grammar used by both publication and render.
 * Only Hero Slider currently accepts authored inner HTML (an overlay slot).
 */
final class PageBuilderComponentTags
{
    public const PATTERN = '~<mskba-component\b(?P<attrs>[^>]*)>(?P<slot>.*?)</mskba-component\s*>~is';

    /** @return list<array<string, string|int>> */
    public static function find(string $html): array
    {
        preg_match_all(self::PATTERN, $html, $matches, PREG_SET_ORDER);

        // Reject missing closing tags and nested components before any HTML
        // sanitization can silently strip a component or its content.
        if (preg_match_all('~<mskba-component\b~i', $html) !== count($matches)
            || preg_match_all('~</mskba-component\s*>~i', $html) !== count($matches)) {
            throw ValidationException::withMessages([
                'draft_html' => 'Проверь закрывающие теги компонентов. Вложенные компоненты не поддерживаются.',
            ]);
        }

        foreach ($matches as $match) {
            if (! preg_match('/\bname="([a-z][a-z0-9_-]{1,79})"/', $match['attrs'], $name)) {
                throw ValidationException::withMessages([
                    'draft_html' => 'У компонента отсутствует корректный атрибут name.',
                ]);
            }

            if (trim($match['slot']) !== '' && $name[1] !== 'hero-slider') {
                throw ValidationException::withMessages([
                    'draft_html' => 'Вложенный HTML сейчас поддерживает только Hero Slider. Остальные компоненты должны быть пустыми.',
                ]);
            }
        }

        return $matches;
    }
}
