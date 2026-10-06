<?php

namespace App\Presentation\Navigation;

final class ContextSubmenuResolver
{
    /**
     * @return array<int, array{label: string, url: string|null, active: bool, visible: bool, children?: array}>
     */
    public function resolve(): array
    {
        $requestPath = '/'.ltrim(request()->path(), '/');
        $sections = config('submenu.sections', []);

        $section = collect($sections)->first(
            fn (array $candidate): bool => in_array($requestPath, $candidate['exact'] ?? [], true),
        );

        $section ??= collect($sections)->first(function (array $candidate) use ($requestPath): bool {
            $pattern = $candidate['pattern'] ?? null;

            return is_string($pattern) && @preg_match($pattern, $requestPath) === 1;
        });

        $handlerClass = $section['handler'] ?? null;

        if (! is_string($handlerClass) || ! class_exists($handlerClass)) {
            return [];
        }

        $handler = app($handlerClass);

        if (! $handler instanceof MenuHandler) {
            return [];
        }

        return array_values(array_filter(
            $handler->items(),
            static fn (array $item): bool => ($item['visible'] ?? true) === true,
        ));
    }
}
