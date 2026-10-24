<?php

namespace App\Modules\PlayInterest\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\Venue\Domain\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PlayIntentManager
{
    /**
     * Save validated input only. The user always comes from the authenticated session,
     * never from caller-supplied JSON or a Telegram identity alias.
     *
     * @param array<string, mixed> $input
     */
    public function save(User $user, array $input, ?PlayIntent $intent = null): PlayIntent
    {
        $canonical = $user->canonical();
        if ($canonical->isBlocked()) {
            abort(403, 'Аккаунт заблокирован.');
        }
        if ($intent !== null && $intent->user_id !== $canonical->id) {
            abort(404);
        }
        $venueId = $input['venue_id'] ?? null;
        if ($venueId !== null) {
            $venue = Venue::query()->with('location.address')->find($venueId);
            if ($venue === null || ! $venue->status->isPubliclyVisible()) {
                throw ValidationException::withMessages(['venue_id' => 'Эта площадка недоступна.']);
            }
            if (($input['include_similar_venues'] ?? false) && ! isset($input['center_latitude'])) {
                $input['center_latitude'] = $venue->location?->address?->latitude;
                $input['center_longitude'] = $venue->location?->address?->longitude;
                if ($input['center_latitude'] === null || $input['center_longitude'] === null) {
                    throw ValidationException::withMessages([
                        'center_latitude' => 'Для поиска похожих площадок выбери центр на карте.',
                    ]);
                }
            }
        }

        $cadence = (string) $input['cadence'];
        $expires = $cadence === 'regular'
            ? CarbonImmutable::now()->addDays(90)
            : CarbonImmutable::now()->addDays(30);
        if ($cadence === 'once') {
            $dates = $input['dates'] ?? [];
            $lastDate = $dates !== [] ? max($dates) : $input['period_until'];
            $expires = CarbonImmutable::parse($lastDate, config('app.timezone', 'Europe/Moscow'))
                ->endOfDay()->utc();
        }

        return DB::transaction(function () use ($canonical, $input, $cadence, $expires, $intent): PlayIntent {
            $intent ??= new PlayIntent;
            $intent->fill([
                'user_id' => $canonical->id,
                'venue_id' => $input['venue_id'] ?? null,
                'include_similar_venues' => (bool) ($input['include_similar_venues'] ?? false),
                'center_latitude' => $input['center_latitude'] ?? null,
                'center_longitude' => $input['center_longitude'] ?? null,
                'radius_km' => ($input['include_similar_venues'] ?? false) ? ($input['radius_km'] ?? 10) : null,
                'cadence' => $cadence,
                'weekdays' => $input['weekdays'] ?? [],
                'dates' => $input['dates'] ?? [],
                'windows' => $input['windows'] ?? [],
                'period_from' => $input['period_from'] ?? null,
                'period_until' => $input['period_until'] ?? null,
                'formats' => $input['formats'] ?? [],
                'duration_minutes' => $input['duration_minutes'] ?? null,
                'access_mode' => $input['access_mode'] ?? 'any',
                'amenity_preferences' => $input['amenity_preferences'] ?? [],
                'notify_matches' => (bool) ($input['notify_matches'] ?? true),
                'notify_offers' => (bool) ($input['notify_offers'] ?? false),
                'status' => 'active',
                'expires_at' => $expires,
            ]);
            $intent->save();

            return $intent->refresh();
        });
    }

    public function setPaused(User $user, PlayIntent $intent, bool $paused): PlayIntent
    {
        if ($intent->user_id !== $user->canonical()->id) {
            abort(404);
        }
        if (! in_array($intent->status, ['active', 'paused'], true)
            || $intent->expires_at?->isPast()) {
            throw ValidationException::withMessages([
                'status' => 'Истёкший или завершённый поиск необходимо настроить заново.',
            ]);
        }
        $intent->forceFill(['status' => $paused ? 'paused' : 'active'])->save();

        return $intent->refresh();
    }
}
