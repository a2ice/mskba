<?php

namespace App\Modules\Template\Application\Services;

use App\Modules\Template\Application\Contracts\TemplateRenderer;
use InvalidArgumentException;

final readonly class BladeTemplateRenderer implements TemplateRenderer
{
    public function __construct(private TrustedTemplateRegistry $registry) {}

    public function render(string $templateKey, array $context): string
    {
        $definition = $this->registry->definition($templateKey);

        if (($definition['driver'] ?? null) !== 'blade') {
            throw new InvalidArgumentException("Unknown trusted Blade template [{$templateKey}].");
        }

        $view = $definition['view'] ?? null;
        if (! is_string($view) || $view === '') {
            throw new InvalidArgumentException("Template [{$templateKey}] has no Blade view.");
        }

        return view($view, $context)->render();
    }
}
