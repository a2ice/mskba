<?php

namespace App\Support\Privacy;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class CookieConsent
{
    public const COOKIE_NAME = 'mskba_cookie_consent';

    public const LIFETIME_MINUTES = 60 * 24 * 365;

    public static function version(): string
    {
        return (string) config('legal.cookie_consent_version', '2026-11-14');
    }

    public static function state(?Request $request = null): ?string
    {
        $value = (string) ($request ?? request())->cookie(self::COOKIE_NAME, '');
        $parts = explode('|', $value, 3);

        if (count($parts) !== 3
            || ! hash_equals(self::version(), $parts[0])
            || ! in_array($parts[1], ['accepted', 'rejected'], true)
            || ! Str::isUuid($parts[2])) {
            return null;
        }

        return $parts[1];
    }

    public static function visitorId(?Request $request = null): string
    {
        $parts = explode('|', (string) ($request ?? request())->cookie(self::COOKIE_NAME, ''), 3);

        return count($parts) === 3 && Str::isUuid($parts[2])
            ? $parts[2]
            : (string) Str::uuid();
    }

    public static function analyticsAllowed(?Request $request = null): bool
    {
        return self::state($request) === 'accepted';
    }
}
