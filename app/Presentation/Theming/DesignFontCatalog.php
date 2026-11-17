<?php

namespace App\Presentation\Theming;

/**
 * Whitelisted, self-hosted families. Never accept arbitrary CSS font names from a form.
 */
final class DesignFontCatalog
{
    public const OPTIONS = [
        'inter' => 'Inter — универсальный',
        'onest' => 'Onest — современный',
        'manrope' => 'Manrope — геометричный',
        'golos-text' => 'Golos Text — лаконичный',
        'commissioner' => 'Commissioner — выразительный',
        'rubik' => 'Rubik — мягкая геометрия',
    ];

    public const FAMILIES = [
        'inter' => 'Inter, Arial, sans-serif',
        'onest' => '"Onest MSKBA", Inter, Arial, sans-serif',
        'manrope' => '"Manrope MSKBA", Inter, Arial, sans-serif',
        'golos-text' => '"Golos Text MSKBA", Inter, Arial, sans-serif',
        'commissioner' => '"Commissioner MSKBA", Inter, Arial, sans-serif',
        'rubik' => '"Rubik MSKBA", Inter, Arial, sans-serif',
    ];

    public static function family(?string $key): string
    {
        return self::FAMILIES[$key ?? 'inter'] ?? self::FAMILIES['inter'];
    }
}
