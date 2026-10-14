<?php

namespace App\Modules\Dashboard\Application\Services;

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
    ];

    /** @return array<string, string> */
    public function parse(string $class, string $css): array
    {
        if (! preg_match('/\A[a-z][a-z0-9_-]{1,39}\z/D', $class)
            || mb_strlen($css) > 3000
            || ! preg_match('/\A\s*\.([a-z][a-z0-9_-]{1,39})\s*\{([^{}]+)\}\s*\z/Ds', $css, $matches)
            || $matches[1] !== $class) {
            throw ValidationException::withMessages([
                'data.css_code' => 'Нужен один CSS-класс, например .bg-green { background-color: green !important; }',
            ]);
        }

        $declarations = [];
        foreach (explode(';', $matches[2]) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            if (! str_contains($segment, ':')) {
                throw ValidationException::withMessages(['data.css_code' => 'Некорректное CSS-свойство.']);
            }
            [$property, $value] = array_map('trim', explode(':', $segment, 2));
            $property = strtolower($property);
            // Explicit allowlist blocks @import, url(), CSS escape sequences, selectors,
            // arbitrary variables and markup break-out from the generated <style>.
            if (! in_array($property, self::PROPERTIES, true)
                || $value === ''
                || ! preg_match('/\A[a-zA-Z0-9#.%(),\s!+\-\/]+\z/D', $value)
                || preg_match('/(?:url|expression|image-set|attr|var|behavior)\s*\(/i', $value)) {
                throw ValidationException::withMessages([
                    'data.css_code' => 'Разрешены только безопасные свойства и значения оформления кнопки, без URL и исполняемых конструкций.',
                ]);
            }
            $declarations[$property] = $value;
        }

        if ($declarations === []) {
            throw ValidationException::withMessages(['data.css_code' => 'Добавь хотя бы одно CSS-свойство.']);
        }

        return $declarations;
    }

    public function compile(DashboardButtonStyle $style): string
    {
        try {
            $declarations = $this->parse((string) $style->class_name, (string) $style->css_code);
        } catch (ValidationException) {
            return '';
        }

        $body = implode(' ', array_map(
            fn (string $prop, string $value) => $prop.':'.$value.';',
            array_keys($declarations), array_values($declarations),
        ));

        return '.app-dashboard-grid .app-dashboard-widget a.button.'.
            $style->class_name.' { '.$body.' }';
    }

    /** @param Collection<int, \App\Modules\Dashboard\Domain\Models\AccountDashboardWidget> $widgets */
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
