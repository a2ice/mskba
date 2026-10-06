<?php

namespace App\Modules\Acquisition\Application\Services;

use App\Modules\Acquisition\Domain\Models\ReferralAttribution;
use App\Modules\Identity\Domain\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class ReferralAttributionService
{
    public const SESSION_KEY = 'referral.pending';

    public function capture(Request $request, User $referrer): void
    {
        $canonical = $referrer->canonical();

        if ($canonical->trashed() || $canonical->isBlocked() || ! $canonical->isConfirmed()) {
            return;
        }

        $request->session()->put(self::SESSION_KEY, [
            'referrer_user_id' => (int) $canonical->id,
            'captured_at' => CarbonImmutable::now()->toIso8601String(),
        ]);
    }

    public function attachPending(Request $request, User $referred): ?ReferralAttribution
    {
        $pending = $request->session()->pull(self::SESSION_KEY);

        if (! is_array($pending) || ! is_numeric($pending['referrer_user_id'] ?? null)) {
            return null;
        }

        $capturedAt = $this->capturedAt($pending['captured_at'] ?? null);

        if ($capturedAt === null || $capturedAt->lt(CarbonImmutable::now()->subDays(7))) {
            return null;
        }

        $referred = $referred->canonical();
        $referrer = User::query()->find((int) $pending['referrer_user_id'])?->canonical();

        if (
            $referrer === null
            || $referrer->trashed()
            || $referrer->isBlocked()
            || ! $referrer->isConfirmed()
            || $referrer->isSameIdentity($referred)
        ) {
            return null;
        }

        if ($referred->created_at !== null && $referred->created_at->lt($capturedAt->subSeconds(5))) {
            return null;
        }

        if ($this->wouldCreateCycle($referrer, $referred)) {
            return null;
        }

        return ReferralAttribution::query()->firstOrCreate(
            ['referred_user_id' => (int) $referred->id],
            [
                'referrer_user_id' => (int) $referrer->id,
                'source' => 'referral_link',
                'captured_at' => $capturedAt,
                'linked_at' => now(),
            ],
        );
    }

    private function capturedAt(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function wouldCreateCycle(User $referrer, User $referred): bool
    {
        $cursor = $referrer;

        for ($depth = 0; $depth < 50; $depth++) {
            if ($cursor->isSameIdentity($referred)) {
                return true;
            }

            $edge = ReferralAttribution::query()
                ->whereIn('referred_user_id', $cursor->identityIds())
                ->first();

            if ($edge === null) {
                return false;
            }

            $next = User::query()->find($edge->referrer_user_id);

            if ($next === null) {
                return false;
            }

            $cursor = $next->canonical();
        }

        return true;
    }
}
