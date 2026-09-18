<?php

namespace Tests\Feature\Acquisition;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AcquisitionDocumentSmokeCommandTest extends TestCase
{
    private string $fakeQrBinary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeQrBinary = storage_path('framework/testing/fake-qrencode');
        @mkdir(dirname($this->fakeQrBinary), 0777, true);

        file_put_contents(
            $this->fakeQrBinary,
            "#!/bin/sh\nprintf '%s' '<svg xmlns=\"http://www.w3.org/2000/svg\"><rect width=\"10\" height=\"10\"/></svg>'\n",
        );
        chmod($this->fakeQrBinary, 0755);

        config()->set('document-templates.qr.binary', $this->fakeQrBinary);
    }

    protected function tearDown(): void
    {
        @unlink($this->fakeQrBinary);

        parent::tearDown();
    }

    public function test_document_smoke_command_checks_qr_template_and_pdf(): void
    {
        Http::fake([
            'http://gotenberg:3000/forms/chromium/convert/html' => Http::response(
                '%PDF-1.7 fake document',
                200,
                ['Content-Type' => 'application/pdf'],
            ),
        ]);

        $this->artisan('acquisition:documents:smoke')
            ->expectsOutputToContain('Acquisition document pipeline OK')
            ->assertSuccessful();

        Http::assertSentCount(1);
    }
}
