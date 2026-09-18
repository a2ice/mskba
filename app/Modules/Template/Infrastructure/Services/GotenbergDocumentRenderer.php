<?php

namespace App\Modules\Template\Infrastructure\Services;

use App\Modules\Template\Application\Contracts\DocumentRenderer;
use App\Modules\Template\Application\DTO\RenderedDocument;
use App\Modules\Template\Domain\Enums\DocumentFormatEnum;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;

final class GotenbergDocumentRenderer implements DocumentRenderer
{
    public function render(string $html, DocumentFormatEnum $format): RenderedDocument
    {
        if ($format !== DocumentFormatEnum::PDF) {
            throw new LogicException("Document format [{$format->value}] is not implemented by the Gotenberg renderer.");
        }

        $baseUrl = rtrim((string) config('document-templates.gotenberg.url'), '/');
        if ($baseUrl === '') {
            throw new RuntimeException('Gotenberg URL is not configured.');
        }

        $response = Http::timeout((int) config('document-templates.gotenberg.timeout_seconds', 30))
            ->retry(2, 250)
            ->attach('files', $html, 'index.html', ['Content-Type' => 'text/html; charset=UTF-8'])
            ->post($baseUrl.'/forms/chromium/convert/html', [
                'printBackground' => 'true',
                'preferCssPageSize' => 'true',
                'generateTaggedPdf' => 'true',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Не удалось сформировать PDF. Сервис документов вернул HTTP '.$response->status().'.');
        }

        return new RenderedDocument($response->body(), $format);
    }
}
