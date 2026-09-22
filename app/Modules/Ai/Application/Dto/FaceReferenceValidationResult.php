<?php

namespace App\Modules\Ai\Application\Dto;

final readonly class FaceReferenceValidationResult
{
    public function __construct(
        public bool $valid,
        public ?string $detectedSlot = null,
        public ?string $skinTone = null,
        public ?string $reason = null,
    ) {}
}
