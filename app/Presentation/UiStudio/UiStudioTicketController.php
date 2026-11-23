<?php

namespace App\Presentation\UiStudio;

use App\Filament\Backoffice\Resources\DesignThemes\DesignThemeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UiStudioTicketController
{
    public function __invoke(Request $request, UiStudioBridgeTicket $tickets): JsonResponse
    {
        abort_unless(DesignThemeResource::canViewAny() && $request->user() !== null, 403);
        abort_unless(config('ui-studio.issuer_enabled') && $request->isSecure(), 503);

        return response()->json([
            'ticket' => $tickets->issue((int) $request->user()->canonical()->getKey(), $request->getSchemeAndHttpHost()),
        ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
