<?php

namespace App\Presentation\Theming;

use Illuminate\Validation\ValidationException;

final class DesignThemeCss
{
    public const COLORS = [
        'bg' => 'Фон страницы',
        'surface' => 'Карточки',
        'surface-raised' => 'Приподнятые поверхности',
        'field' => 'Поля ввода',
        'text' => 'Основной текст',
        'muted' => 'Вторичный текст',
        'faint' => 'Метаданные',
        'accent' => 'Акцент',
        'accent-hover' => 'Акцент — hover',
        'accent-active' => 'Акцент — active',
        'on-accent' => 'Текст на акценте',
        'line' => 'Граница карточки',
        'control-line' => 'Граница поля',
        'success' => 'Успех',
        'warning' => 'Предупреждение',
        'danger' => 'Ошибка',
        'info' => 'Информация',
    ];

    public function validate(array $colors, ?string $customCss): void
    {
        if (array_keys($colors) !== array_keys(self::COLORS)) {
            // Filament nested form fields preserve this stable list.
            if (array_diff(array_keys(self::COLORS), array_keys($colors)) || array_diff(array_keys($colors), array_keys(self::COLORS))) {
                throw ValidationException::withMessages(['colors' => 'Укажи все цвета темы.']);
            }
        }

        foreach ($colors as $name => $value) {
            if (! is_string($value) || ! preg_match('/\A#[0-9a-fA-F]{6}(?:[0-9a-fA-F]{2})?\z/D', $value)) {
                throw ValidationException::withMessages(['colors' => "Недопустимый цвет {$name}. Используй HEX (#RRGGBB)."]);
            }
        }

        $css = trim((string) $customCss);
        if ($css === '') {
            return;
        }
        if (strlen($css) > 12000 || ! preg_match('/\A:root\s*\{([^{}]*)\}\s*\z/sD', $css, $match)) {
            throw ValidationException::withMessages(['css_code' => 'Разрешён только один блок :root { --token: значение; }.']);
        }

        foreach (explode(';', trim($match[1])) as $declaration) {
            if (trim($declaration) === '') {
                continue;
            }
            if (! preg_match('/\A\s*(--[a-z][a-z0-9-]{1,60})\s*:\s*(\S(?:.*\S)?)\s*\z/sD', $declaration, $parts)) {
                throw ValidationException::withMessages(['css_code' => 'Допускаются только CSS-переменные --name: value.']);
            }
            $value = $parts[2];
            if (preg_match('/url\s*\(|@|expression|behavior|binding|javascript|data:|[<>\\\\]/i', $value)
                || ! preg_match('/\A[a-zA-Z0-9#,.\s%()"+\/*-]+\z/D', $value)) {
                throw ValidationException::withMessages(['css_code' => 'Запрещены URL, импорты и небезопасные CSS-значения.']);
            }
        }
    }

    public function render(DesignTheme $theme): string
    {
        if (! in_array($theme->mode, ['dark', 'light'], true)) {
            throw ValidationException::withMessages(['mode' => 'Допускается только тёмная или светлая схема.']);
        }

        $colors = (array) $theme->colors;
        $this->validate($colors, $theme->css_code);
        $declarations = ['color-scheme: '.$theme->mode.';'];
        foreach (self::COLORS as $name => $label) {
            $declarations[] = '--'.$name.':'.$colors[$name].';';
        }

        $bodyFont = (string) ($theme->body_font ?? 'inter');
        $headingFont = (string) ($theme->heading_font ?? 'inter');
        if (! isset(DesignFontCatalog::FAMILIES[$bodyFont], DesignFontCatalog::FAMILIES[$headingFont])) {
            throw ValidationException::withMessages(['body_font' => 'Выбери шрифты из утверждённой коллекции.']);
        }

        $fontDeclarations = [
            '--theme-font-family: '.DesignFontCatalog::family($bodyFont).';',
            '--theme-heading-font-family: '.DesignFontCatalog::family($headingFont).';',
            '--light-display-font-family: '.DesignFontCatalog::family($headingFont).';',
        ];
        $glowColor = $theme->widget_glow_color;
        if ($glowColor !== null && $glowColor !== '') {
            $strength = $theme->widget_glow_strength ?? 20;
            if (! is_string($glowColor) || ! preg_match('/\\A#[a-fA-F0-9]{6}\\z/D', $glowColor)
                || ! is_numeric($strength) || (int) $strength < 0 || (int) $strength > 40) {
                throw ValidationException::withMessages(['widget_glow_color' => 'Недопустимые параметры свечения карточек.']);
            }
            $alpha = strtoupper(str_pad(dechex((int) round(((int) $strength) * 255 / 100)), 2, '0', STR_PAD_LEFT));
            $fontDeclarations[] = '--surface-corner-glow: '.strtoupper($glowColor).$alpha.';';
        }

        $sidebarMap = [
            'sidebar_background' => '--sidebar-background',
            'sidebar_text' => '--sidebar-text',
            'sidebar_hover' => '--sidebar-hover',
            'sidebar_active_background' => '--sidebar-active-background',
            'sidebar_active_text' => '--sidebar-active-text',
        ];
        foreach ($sidebarMap as $column => $token) {
            $value = $theme->{$column};
            if ($value === null || $value === '') {
                continue;
            }
            if (! is_string($value) || ! preg_match('/\\A#[a-fA-F0-9]{6}\\z/D', $value)) {
                throw ValidationException::withMessages([$column => 'Используй HEX-цвет #RRGGBB.']);
            }
            $fontDeclarations[] = $token.': '.strtoupper($value).';';
        }

        // Applied after the editable legacy CSS to ensure visual controls win
        // while retaining backwards-compatible custom properties and revisions.
        return "/* MSKBA appearance: {$theme->slug} */\n:root {\n    "
            .implode("\n    ", $declarations)."\n}\n"
            .trim((string) $theme->css_code)."\n:root {\n    "
            .implode("\n    ", $fontDeclarations)."\n}\n";
    }

    public function revision(DesignTheme $theme): string
    {
        return substr(hash('sha256', $this->render($theme)), 0, 16);
    }
}
