<?php

namespace App\Support\Privacy;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CookieConsent
{
    // Legacy cookie is expired on the next decision; it is never trusted as
    // proof of consent after introducing account and session scoped choices.
    public const COOKIE_NAME = 'mskba_cookie_consent';

    public const SESSION_CHOICE_KEY = 'mskba.cookie_consent.choice';

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

        if (! $request->hasSession()) {
            return null;
        }

        $choice = $request->session()->get(self::SESSION_CHOICE_KEY);

        return is_array($choice)
            && hash_equals(self::version(), (string) ($choice['version'] ?? ''))
            ? self::validatedChoice($choice['analytics'] ?? null)
            : null;
    }

    private static function validatedChoice(mixed $value): ?string
    {
        return in_array($value, ['accepted', 'rejected'], true) ? $value : null;
    }

    public static function rememberGuestChoice(Request $request, string $choice): void
    {
        $request->session()->put(self::SESSION_CHOICE_KEY, [
            'version' => self::version(),
            'analytics' => $choice,
        ]);
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
