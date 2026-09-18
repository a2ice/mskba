<?php

namespace App\Modules\Template\Application\Contracts;

interface TemplateRenderer
{
    /** @param array<string, mixed> $context */
    public function render(string $templateKey, array $context): string;
}
