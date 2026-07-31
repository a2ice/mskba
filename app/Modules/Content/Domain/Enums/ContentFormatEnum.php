<?php

namespace App\Modules\Content\Domain\Enums;

enum ContentFormatEnum: string
{
    case MARKDOWN = 'markdown';
    case SAFE_HTML = 'safe_html';

    public function label(): string
    {
        return match ($this) {
            self::MARKDOWN => 'Markdown',
            self::SAFE_HTML => 'Безопасный HTML',
        };
    }
}
