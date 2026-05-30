<?php

namespace App\Presentation\Navigation;

final class ConfigMenuResolver implements MenuResolver
{
    /**
     * @return array<int, array{label: string, url: string, active: bool, visible: bool}>
     */
    public function resolve(string $page): array
    {
        $handlerClass = config("menus.$page");

        if (! is_string($handlerClass) || ! class_exists($handlerClass)) {
            return [];
        }

        $handler = app($handlerClass);

        if (! $handler instanceof MenuHandler) {
            return [];
        }

        return $handler->items();
    }
}
