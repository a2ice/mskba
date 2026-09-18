<?php

namespace App\Modules\Template\Application\Services;

use App\Modules\Template\Application\Contracts\TemplateRenderer;
use InvalidArgumentException;

final class BladeTemplateRenderer implements TemplateRenderer
{
    public function render(string $templateKey, array $context): string
    {
        $registry = config('document-templates.registry', []);
        $definition = is_array($registry) ? ($registry[$templateKey] ?? null) : null;

        if (! is_array($definition) || ($definition['driver'] ?? null) !== 'blade') {
            throw new InvalidArgumentException("Unknown trusted template [{$templateKey}].");
        }

        $view = $definition['view'] ?? null;
        if (! is_string($view) || $view === '') {
            throw new InvalidArgumentException("Template [{$templateKey}] has no Blade view.");
        }

        return view($view, $context)->render();
    }
}
