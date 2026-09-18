<?php

namespace Tests\Feature\Template;

use App\Modules\Template\Application\Contracts\DocumentRenderer;
use App\Modules\Template\Domain\Enums\DocumentFormatEnum;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

final class GotenbergDocumentRendererTest extends TestCase
{
    public function test_pdf_renderer_posts_html_to_gotenberg(): void
    {
        Http::fake([
            'http://gotenberg:3000/forms/chromium/convert/html' => Http::response(
                '%PDF-1.7 fake',
                200,
                ['Content-Type' => 'application/pdf'],
            ),
        ]);

        $document = app(DocumentRenderer::class)->render(
            '<html><body>MSKBA flyer</body></html>',
            DocumentFormatEnum::PDF,
        );

        $this->assertSame('%PDF-1.7 fake', $document->contents);
        $this->assertSame('application/pdf', $document->mimeType());

        Http::assertSent(fn ($request): bool => $request->url() === 'http://gotenberg:3000/forms/chromium/convert/html'
            && $request->method() === 'POST');
    }

    public function test_gotenberg_renderer_does_not_fake_docx_support(): void
    {
        $this->expectException(LogicException::class);

        app(DocumentRenderer::class)->render('<html></html>', DocumentFormatEnum::DOCX);
    }
}
