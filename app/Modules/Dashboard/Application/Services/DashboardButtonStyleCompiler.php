<?php

namespace App\Modules\Dashboard\Application\Services;

use App\Modules\Dashboard\Domain\Models\AccountDashboardWidget;
use App\Modules\Dashboard\Domain\Models\DashboardButtonStyle;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class DashboardButtonStyleCompiler
{
    private const PROPERTIES = [
        'background', 'background-color', 'color', 'border', 'border-color',
        'border-radius', 'box-shadow', 'font-size', 'font-weight', 'letter-spacing',
        'line-height', 'padding', 'padding-left', 'padding-right', 'padding-top',
        'padding-bottom', 'text-decoration', 'text-transform', 'opacity',
        'outline', 'outline-color', 'transition', 'transform',
        'overflow', 'pointer-events', 'position', 'top', 'left', 'right',
        'bottom', 'width', 'height', 'content',
    ];

    /**
     * Accept a single named class, multiple blocks, optional state pseudoclasses
     * and ::before/::after. This is a restricted language, NOT general CSS.
     *
     * @return list<array{selector: string, declarations: array<string, string>}>
     */
    public function parse(string $class, string $css): array
    {
        if (! preg_match('/\A[a-z][a-z0-9_-]{1,39}\z/D', $class)
            || mb_strlen($css) > 3000 || trim($css) === '') {
            $this->invalid('Укажи один CSS-класс и правила для него (до 3000 символов).');
        }

        // CSS comments are valid inside rule bodies (including before a
        // declaration). Strip them before the strict property parser; only
        // validated declarations are emitted by compile().
        $css = preg_replace('~/\*.*?\*/~s', '', $css);
        if ($css === null || str_contains($css, '/*') || str_contains($css, '*/')) {
            $this->invalid('Некорректный CSS-комментарий.');
        }

        // Pasted CSS may use the mathematical minus symbol in a negative
        // length/angle. Normalize it; the existing value allowlist still
        // rejects unsupported properties, units and functions.
        $css = preg_replace('/\x{2212}(?=[0-9])/u', '-', $css);
        if ($css === null || trim($css) === '') {
            $this->invalid('Добавь хотя бы одно CSS-правило.');
        }
        $css = trim($css);
        $length = strlen($css);
        $offset = 0;
        $rules = [];
        $pattern = '/\G\s*\.([a-z][a-z0-9_-]{1,39})(:(?:hover|focus-visible|focus|active))?(::?(?:before|after))?\s*\{([^{}]+)\}\s*/';

        while ($offset < $length) {
            if (! preg_match($pattern, $css, $parts, 0, $offset)
                || $parts[1] !== $class || count($rules) >= 12) {
                $this->invalid('Разрешены до 12 правил для одного класса: .'.$class.', :hover, :focus-visible, ::before, ::after (можно сочетать).');
            }

            $offset += strlen($parts[0]);
            $state = $parts[2] ?? '';
            $pseudo = $parts[3] ?? '';
            if ($pseudo !== '') {
                $pseudo = '::'.ltrim($pseudo, ':');
            }
            $isPseudo = $pseudo !== '';
            $declarations = [];

            foreach (explode(';', $parts[4]) as $segment) {
                $segment = trim($segment);
                if ($segment === '') {
                    continue;
                }
                if (! str_contains($segment, ':')) {
                    $this->invalid('Некорректное CSS-свойство.');
                }
                [$property, $value] = array_map('trim', explode(':', $segment, 2));
                $property = strtolower($property);

                if (! in_array($property, self::PROPERTIES, true) || $value === '') {
                    $this->invalid('Это свойство не разрешено для оформления кнопок.');
                }

                if ($property === 'content') {
                    if (! $isPseudo || ! in_array($value, ['""', "''"], true)) {
                        $this->invalid('Для псевдоэлемента допускается только пустой content: "".');
                    }
                    $value = '""';
                } else {
                    if (! preg_match('/\A[a-zA-Z0-9#.%(),\s!+\-\/]+\z/D', $value)
                        || preg_match('/(?:url|expression|image-set|attr|var|behavior)\s*\(/i', $value)) {
                        $this->invalid('В CSS запрещены внешние ресурсы, скрипты, переменные и управляющие конструкции.');
                    }
                }

                if ($property === 'position' && ! ($isPseudo ? $value === 'absolute' : $value === 'relative')) {
                    $this->invalid('На кнопке допускается position: relative, на ::before/::after — position: absolute.');
                }
                if ($property === 'overflow' && ($isPseudo || ! in_array($value, ['hidden', 'clip'], true))) {
                    $this->invalid('Поддерживается только overflow: hidden или clip на самой кнопке.');
                }
                if ($property === 'pointer-events' && (! $isPseudo || $value !== 'none')) {
                    $this->invalid('Для псевдоэлементов допускается только pointer-events: none.');
                }
                if (in_array($property, ['top', 'left', 'right', 'bottom', 'width', 'height'], true)
                    && (! $isPseudo || ! preg_match('/\A(?:0|100%|[0-9]{1,2}%|[0-9]{1,3}px)\z/D', $value))) {
                    $this->invalid('Размеры и координаты задаются только псевдоэлементам: 0, 0–100%, либо до 999px.');
                }

                $declarations[$property] = $value;
            }

            if ($declarations === []) {
                $this->invalid('Добавь хотя бы одно CSS-свойство в каждое правило.');
            }
            // Decorative overlays must never intercept mouse/touch interaction.
            if ($isPseudo) {
                $declarations['pointer-events'] = 'none';
            }

            $rules[] = [
                'selector' => '.'.$class.$state.$pseudo,
                'declarations' => $declarations,
            ];
        }

        return $rules;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['data.css_code' => $message]);
    }

    public function compile(DashboardButtonStyle $style): string
    {
        try {
            $rules = $this->parse((string) $style->class_name, (string) $style->css_code);
        } catch (ValidationException) {
            return '';
        }

        return implode("\n", array_map(static function (array $rule): string {
            $body = implode(' ', array_map(
                static fn (string $prop, string $value): string => $prop.':'.$value.';',
                array_keys($rule['declarations']), array_values($rule['declarations']),
            ));

            return '.app-dashboard-grid .app-dashboard-widget a.button'.$rule['selector'].' { '.$body.' }';
        }, $rules));
    }

    /** @param Collection<int, AccountDashboardWidget> $widgets */
    public function cssForWidgets(Collection $widgets): string
    {
        $names = [];
        foreach ($widgets as $widget) {
            foreach ($widget->actions ?? [] as $action) {
                foreach (($action['classes'] ?? []) as $class) {
                    if (is_string($class)) {
                        $names[] = $class;
                    }
                }
            }
        }

        if ($names === []) {
            return '';
        }

        return DashboardButtonStyle::query()->where('enabled', true)
            ->whereIn('class_name', array_unique($names))->orderBy('id')->get()
            ->map(fn (DashboardButtonStyle $style): string => $this->compile($style))
            ->filter()->implode("\n");
    }
}
