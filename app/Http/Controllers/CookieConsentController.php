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
        $accountId = $request->user()?->canonical()->getKey();

        DB::transaction(function () use ($request, $choice, $visitorId, $version, $accountId): void {
            // Preserve the history of the decision independently from the
            // current preference (which must survive audit-log retention).
            DB::table('cookie_consent_events')->insert([
                'visitor_id' => $visitorId,
                'user_id' => $request->user()?->getAuthIdentifier(),
                'analytics_choice' => $choice,
                'notice_version' => $version,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'created_at' => now(),
            ]);

            if ($accountId !== null) {
                DB::table('user_cookie_consent_preferences')->upsert([
                    [
                        'user_id' => $accountId,
                        'analytics_choice' => $choice,
                        'notice_version' => $version,
                        'updated_at' => now(),
                    ],
                ], ['user_id'], ['analytics_choice', 'notice_version', 'updated_at']);
            }
        });

        $response = $request->expectsJson()
            ? response()->json(['analytics' => $choice])
            : redirect()->back(303);

        // Only guest decisions update the browser preference. Account choices
        // remain independent and never inherit a guest preference.
        $response->header('Cache-Control', 'private, no-store')
            ->cookie(cookie()->forget(CookieConsent::LEGACY_COOKIE_NAME));

        if ($accountId === null) {
            $response->cookie(CookieConsent::guestCookie($choice));
        }

        return $response;
    }
}
