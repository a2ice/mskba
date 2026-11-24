<?php

namespace App\Presentation\UiStudio;

use Illuminate\Support\Facades\Cache;

/**
 * Scope-limited, single-use Filament -> isolated UI Studio assertion.
 * Never reuse APP_KEY or a user session cookie between the two runtimes.
 */
final class UiStudioBridgeTicket
{
    public function issue(int $userId, string $origin): string
    {
        abort_unless(in_array($origin, config('ui-studio.parent_origins', []), true), 403);

        $payload = [
            'v' => 1,
            'aud' => 'mskba-ui-studio',
            'sub' => $userId,
            'origin' => $origin,
            'iat' => time(),
            'exp' => time() + 60,
            'nonce' => bin2hex(random_bytes(16)),
        ];
        $encoded = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $encoded.'.'.hash_hmac('sha256', $encoded, $this->secret());
    }

    /** @return array<string, mixed> */
    public function consume(string $ticket): array
    {
        $parts = explode('.', $ticket);
        abort_unless(count($parts) === 2 && strlen($ticket) <= 2048, 403);
        [$encoded, $signature] = $parts;
        abort_unless(ctype_xdigit($signature) && strlen($signature) === 64
            && hash_equals(hash_hmac('sha256', $encoded, $this->secret()), $signature), 403);

        $payload = json_decode(base64_decode(strtr($encoded, '-_', '+/'), true) ?: '', true);
        $now = time();
        abort_unless(is_array($payload)
            && ($payload['v'] ?? null) === 1
            && ($payload['aud'] ?? null) === 'mskba-ui-studio'
            && is_int($payload['sub'] ?? null) && $payload['sub'] > 0
            && is_string($payload['origin'] ?? null)
            && in_array($payload['origin'], config('ui-studio.parent_origins', []), true)
            && is_int($payload['iat'] ?? null) && $payload['iat'] <= $now + 5 && $payload['iat'] >= $now - 65
            && is_int($payload['exp'] ?? null) && $payload['exp'] > $now && $payload['exp'] <= $now + 65
            && is_string($payload['nonce'] ?? null)
            && preg_match('/\A[a-f0-9]{32}\z/D', $payload['nonce']), 403);

        // Deliberately a persistent local store, never the ephemeral "array" store.
        // A second redemption, even from another browser, is rejected.
        $key = 'ui-studio:redeemed:'.hash('sha256', $payload['nonce']);
        abort_unless(Cache::store('file')->add($key, true, 180), 409);

        return $payload;
    }

    private function secret(): string
    {
        $secret = config('ui-studio.bridge_secret');
        abort_unless(is_string($secret) && strlen($secret) >= 32, 503, 'UI Studio bridge is not configured');

        return $secret;
    }
}
