<?php

namespace App\Http\Controllers;

use App\Support\Privacy\CookieConsent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class CookieConsentController extends Controller
{
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'analytics' => ['required', Rule::in(['accepted', 'rejected'])],
        ]);

        $choice = $validated['analytics'];
        $visitorId = CookieConsent::visitorId($request);
        $version = CookieConsent::version();

        DB::table('cookie_consent_events')->insert([
            'visitor_id' => $visitorId,
            'user_id' => $request->user()?->getAuthIdentifier(),
            'analytics_choice' => $choice,
            'notice_version' => $version,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'created_at' => now(),
        ]);

        $response = $request->expectsJson()
            ? response()->json(['analytics' => $choice])
            : redirect()->back(303);

        return $response
            ->header('Cache-Control', 'private, no-store')
            ->cookie(cookie(
                name: CookieConsent::COOKIE_NAME,
                value: $version.'|'.$choice.'|'.$visitorId,
                minutes: CookieConsent::LIFETIME_MINUTES,
                secure: $request->isSecure(),
                httpOnly: true,
                sameSite: 'lax',
            ));
    }
}
