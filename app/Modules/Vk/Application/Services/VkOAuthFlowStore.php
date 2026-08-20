<?php

namespace App\Modules\Vk\Application\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class VkOAuthFlowStore
{
    /** @return array{state: string, code_verifier: string, code_challenge: string} */
    public function issue(Request $request, string $mode, string $redirectUrl, ?int $userId): array
    {
        $state = Str::random(64);
        $verifier = Str::random(96);
        $flows = $request->session()->get('vk.oauth_flows', []);
        $flows = is_array($flows) ? $this->withoutExpired($flows) : [];
        $flows[$state] = [
            'mode' => $mode,
            'redirect_url' => $redirectUrl,
            'user_id' => $userId,
            'code_verifier' => $verifier,
            'expires_at' => now()->addSeconds(max(60, (int) config('vk.flow_ttl', 600)))->timestamp,
        ];
        $request->session()->put('vk.oauth_flows', $flows);

        return [
            'state' => $state,
            'code_verifier' => $verifier,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        ];
    }

    /** @return array{mode: string, redirect_url: string, user_id: ?int, code_verifier: string} */
    public function consume(Request $request, string $state): array
    {
        $flows = $request->session()->pull('vk.oauth_flows', []);
        $flow = is_array($flows) ? ($flows[$state] ?? null) : null;

        if (! is_array($flow) || (int) ($flow['expires_at'] ?? 0) < now()->timestamp) {
            throw new InvalidArgumentException('Сессия входа через VK ID истекла. Начните вход заново.');
        }

        unset($flows[$state]);
        $request->session()->put('vk.oauth_flows', $this->withoutExpired($flows));

        return [
            'mode' => (string) $flow['mode'],
            'redirect_url' => (string) $flow['redirect_url'],
            'user_id' => isset($flow['user_id']) ? (int) $flow['user_id'] : null,
            'code_verifier' => (string) $flow['code_verifier'],
        ];
    }

    /** @param array<string, mixed> $flows @return array<string, mixed> */
    private function withoutExpired(array $flows): array
    {
        return array_filter($flows, fn (mixed $flow): bool => is_array($flow)
            && (int) ($flow['expires_at'] ?? 0) >= now()->timestamp);
    }
}
