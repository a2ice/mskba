<?php

namespace App\Support\Privacy;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

final class CookieConsent
{
    public const LEGACY_COOKIE_NAME = 'mskba_cookie_consent';

    public const COOKIE_NAME = 'mskba_guest_cookie_consent_v2';

    public const GUEST_COOKIE_DAYS = 365;

    public const SESSION_VISITOR_KEY = 'mskba.cookie_consent.visitor_id';

    private const REQUEST_STATE_KEY = 'mskba.resolved_cookie_consent';

    public static function version(): string
    {
        return (string) config('legal.cookie_consent_version', '2026-11-14');
    }

    public static function state(?Request $request = null): ?string
    {
        $request ??= request();

        // The banner and analytics snippet both ask on the same request.
        if ($request->attributes->has(self::REQUEST_STATE_KEY)) {
            return $request->attributes->get(self::REQUEST_STATE_KEY);
        }

        $state = self::resolveState($request);
        $request->attributes->set(self::REQUEST_STATE_KEY, $state);

        return $state;
    }

    private static function resolveState(Request $request): ?string
    {
        if ($user = $request->user()) {
            // An authenticated account NEVER inherits a guest/browser choice.
            $preference = DB::table('user_cookie_consent_preferences')
                ->where('user_id', $user->canonical()->getKey())
                ->first(['analytics_choice', 'notice_version']);

            return $preference !== null
                && hash_equals(self::version(), (string) $preference->notice_version)
                ? self::validatedChoice($preference->analytics_choice)
                : null;
        }

        // The web middleware decrypts and authenticates this host-only cookie.
        // Never use the legacy cookie or an account's decision as guest consent.
        $cookie = $request->cookie(self::COOKIE_NAME);
        if (! is_string($cookie)) {
            return null;
        }

        $parts = explode('|', $cookie);
        if (count($parts) !== 3) {
            return null;
        }

        [$version, $choice, $expiresAt] = $parts;

        return hash_equals(self::version(), $version)
            && ctype_digit($expiresAt)
            && (int) $expiresAt > now()->timestamp
            ? self::validatedChoice($choice)
            : null;
    }

    private static function validatedChoice(mixed $value): ?string
    {
        return in_array($value, ['accepted', 'rejected'], true) ? $value : null;
    }

    public static function guestCookie(string $choice): Cookie
    {
        $expiresAt = now()->addDays(self::GUEST_COOKIE_DAYS)->timestamp;

        return Cookie::create(
            self::COOKIE_NAME,
            self::version().'|'.$choice.'|'.$expiresAt,
            $expiresAt,
            '/',
            null,
            true,
            true,
            false,
            Cookie::SAMESITE_LAX,
        );
    }

    public static function visitorId(?Request $request = null): string
    {
        $request ??= request();
        if (! $request->hasSession()) {
            return (string) Str::uuid();
        }

        $visitorId = $request->session()->get(self::SESSION_VISITOR_KEY);
        if (is_string($visitorId) && Str::isUuid($visitorId)) {
            return $visitorId;
        }

        $visitorId = (string) Str::uuid();
        $request->session()->put(self::SESSION_VISITOR_KEY, $visitorId);

        return $visitorId;
    }

    public static function analyticsAllowed(?Request $request = null): bool
    {
        return self::state($request) === 'accepted';
    }
}
