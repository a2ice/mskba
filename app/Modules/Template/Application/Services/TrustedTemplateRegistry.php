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

    /** @return array<string, array<string, mixed>> */
    public function fieldDefinitions(string $templateKey): array
    {
        $fields = $this->definition($templateKey)['fields'] ?? [];

        return is_array($fields) ? $fields : [];
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
