<?php

namespace App\Modules\Identity\Infrastructure\Http\Middleware;

use App\Modules\Identity\Domain\Models\UserFingerprint;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class RecordBrowserFingerprint
{
    private const COOKIE_NAME = 'mskba_browser_fp';
    private const COOKIE_LIFETIME_MINUTES = 60 * 24 * 365 * 5;

    public function handle(Request $request, Closure $next): Response
    {
        $fingerprintId = $this->fingerprintId($request);
        $fingerprint = null;

        if ($this->fingerprintsTableExists()) {
            $fingerprint = $this->recordVisit($request, $fingerprintId);
            $request->attributes->set('browser_fingerprint', $fingerprint);
        }

        $response = $next($request);

        if ($fingerprint !== null && $request->user() !== null) {
            $this->recordAuthenticatedUser($fingerprint, $request->user()->id);
        }

        if (! $request->cookies->has(self::COOKIE_NAME)) {
            Cookie::queue(cookie(
                name: self::COOKIE_NAME,
                value: $fingerprintId,
                minutes: self::COOKIE_LIFETIME_MINUTES,
                secure: $request->isSecure(),
                httpOnly: true,
                sameSite: 'lax',
            ));
        }

        return $response;
    }

    private function fingerprintId(Request $request): string
    {
        $value = (string) $request->cookies->get(self::COOKIE_NAME, '');

        return Str::isUuid($value) ? $value : (string) Str::uuid();
    }

    private function recordVisit(Request $request, string $fingerprintId): UserFingerprint
    {
        $now = now();
        $fingerprintHash = $this->hash($fingerprintId);
        $fingerprint = UserFingerprint::query()
            ->where('fingerprint_hash', $fingerprintHash)
            ->first();

        if ($fingerprint === null) {
            return UserFingerprint::query()->create([
                'fingerprint_hash' => $fingerprintHash,
                'browser_signature_hash' => $this->browserSignatureHash($request),
                'ip_hash' => $this->ipHash($request),
                'visits_count' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);
        }

        $fingerprint->forceFill([
            'browser_signature_hash' => $this->browserSignatureHash($request),
            'ip_hash' => $this->ipHash($request),
            'visits_count' => $fingerprint->visits_count + 1,
            'last_seen_at' => $now,
        ])->save();

        return $fingerprint;
    }

    private function recordAuthenticatedUser(UserFingerprint $fingerprint, int $userId): void
    {
        $now = now();

        DB::table('user_fingerprint_user')->updateOrInsert(
            [
                'user_fingerprint_id' => $fingerprint->id,
                'user_id' => $userId,
            ],
            [
                'last_authenticated_at' => $now,
                'updated_at' => $now,
            ],
        );

        DB::table('user_fingerprint_user')
            ->where('user_fingerprint_id', $fingerprint->id)
            ->where('user_id', $userId)
            ->whereNull('first_authenticated_at')
            ->update([
                'first_authenticated_at' => $now,
                'created_at' => $now,
            ]);

        DB::table('user_fingerprint_user')
            ->where('user_fingerprint_id', $fingerprint->id)
            ->where('user_id', $userId)
            ->increment('authentications_count');
    }

    private function browserSignatureHash(Request $request): string
    {
        return $this->hash(implode('|', [
            (string) $request->userAgent(),
            (string) $request->headers->get('accept-language', ''),
            (string) $request->headers->get('accept-encoding', ''),
            (string) $request->headers->get('sec-ch-ua', ''),
            (string) $request->headers->get('sec-ch-ua-platform', ''),
            (string) $request->headers->get('sec-ch-ua-mobile', ''),
        ]));
    }

    private function ipHash(Request $request): ?string
    {
        $ip = $request->ip();

        return $ip === null ? null : $this->hash($ip);
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function fingerprintsTableExists(): bool
    {
        return Schema::hasTable('user_fingerprints')
            && Schema::hasTable('user_fingerprint_user');
    }
}
