<?php

namespace App\Modules\Template\Application\Services;

use InvalidArgumentException;

final class TrustedTemplateRegistry
{
    /** @return array<string, mixed> */
    public function definition(string $templateKey): array
    {
        $registry = config('document-templates.registry', []);
        $definition = is_array($registry) ? ($registry[$templateKey] ?? null) : null;

        if (! is_array($definition)) {
            throw new InvalidArgumentException("Unknown trusted template [{$templateKey}].");
        }

        return $definition;
    }

    public function assetPath(string $templateKey, string $slot): ?string
    {
        $assets = $this->definition($templateKey)['assets'] ?? [];
        $path = is_array($assets) ? ($assets[$slot] ?? null) : null;

        return is_string($path) && trim($path) !== ''
            ? trim($path)
            : null;
    }
}
