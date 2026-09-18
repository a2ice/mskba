<?php

namespace App\Modules\Template\Domain\Enums;

enum DocumentFormatEnum: string
{
    case PDF = 'pdf';
    case DOCX = 'docx';

    public function mimeType(): string
    {
        return match ($this) {
            self::PDF => 'application/pdf',
            self::DOCX => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        };
    }
}
