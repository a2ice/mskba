<?php

namespace App\Modules\Template\Application\DTO;

use App\Modules\Template\Domain\Enums\DocumentFormatEnum;

final readonly class RenderedDocument
{
    public function __construct(
        public string $contents,
        public DocumentFormatEnum $format,
    ) {}

    public function mimeType(): string
    {
        return $this->format->mimeType();
    }

    public function extension(): string
    {
        return $this->format->value;
    }
}
