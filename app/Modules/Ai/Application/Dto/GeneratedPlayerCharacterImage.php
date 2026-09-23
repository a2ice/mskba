<?php

namespace App\Modules\Ai\Application\Dto;

final readonly class GeneratedPlayerCharacterImage
{
    public function __construct(
        public string $contents = '',
        public string $mime = '',
        public string $status = 'completed',
        public ?string $generationId = null,
    ) {}

    public static function pending(string $generationId): self
    {
        return new self(status: 'pending', generationId: $generationId);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
