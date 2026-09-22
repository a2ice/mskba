<?php

namespace App\Modules\Ai\Application\Dto;

final readonly class GeneratedPlayerCharacterImage
{
    public function __construct(
        public string $contents,
        public string $mime,
    ) {}
}
