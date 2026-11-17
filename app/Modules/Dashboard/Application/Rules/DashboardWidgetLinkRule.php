<?php

namespace App\Modules\Dashboard\Application\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accept a root-relative portal link (/partners?ref=app#section) or a full
 * HTTP(S) URL. Never accept scheme-relative paths or JavaScript schemes.
 */
final class DashboardWidgetLinkRule implements ValidationRule
{
    public static function isValid(string $value): bool
    {
        if ($value === '' || trim($value) !== $value
            || preg_match('/[\x00-\x20\x7f]/', $value) === 1
            || str_contains($value, '\\')) {
            return false;
        }

        if (str_starts_with($value, '/')) {
            return ! str_starts_with($value, '//');
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $host = parse_url($value, PHP_URL_HOST);

        return in_array($scheme, ['http', 'https'], true)
            && is_string($host) && $host !== ''
            && parse_url($value, PHP_URL_USER) === null
            && parse_url($value, PHP_URL_PASS) === null;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail('Укажи внутреннюю ссылку от / (например, /partners) или внешний адрес HTTPS/HTTP.');
        }
    }
}
