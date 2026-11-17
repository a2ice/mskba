<?php

namespace App\Http\Controllers\Backoffice;

use App\Filament\Backoffice\Pages\RestApiExplorer;
use Illuminate\Http\Response;

final class OpenApiDocumentController
{
    public function __invoke(): Response
    {
        // The same permission controls the page and the raw document.
        abort_unless(RestApiExplorer::canAccess(), 403);

        return response(
            file_get_contents(base_path('docs/api/openapi-v1.json')),
            200,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
