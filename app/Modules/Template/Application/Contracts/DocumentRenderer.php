<?php

namespace App\Modules\Template\Application\Contracts;

use App\Modules\Template\Application\DTO\RenderedDocument;
use App\Modules\Template\Domain\Enums\DocumentFormatEnum;

interface DocumentRenderer
{
    public function render(string $html, DocumentFormatEnum $format): RenderedDocument;
}
