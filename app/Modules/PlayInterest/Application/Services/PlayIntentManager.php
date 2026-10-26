<?php

namespace App\Modules\PlayInterest\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\Venue\Domain\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
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
        $requestId = $input['client_request_id'] ?? null;
        if ($intent === null && is_string($requestId)) {
            $existing = PlayIntent::query()->where('user_id', $canonical->id)
                ->where('client_request_id', $requestId)->first();
            if ($existing !== null) {
                return $existing;
            }
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

        try {
            return DB::transaction(function () use ($canonical, $input, $cadence, $expires, $intent, $venueId): PlayIntent {
            $intent ??= new PlayIntent;
            $intent->fill([
                'user_id' => $canonical->id,
                'name' => $input['name'] ?? null,
                'client_request_id' => $intent->client_request_id ?: ($input['client_request_id'] ?? null),
                'venue_id' => $input['venue_id'] ?? null,
                'include_similar_venues' => (bool) ($input['include_similar_venues'] ?? false),
                'show_on_venue' => (bool) ($input['show_on_venue'] ?? $intent->show_on_venue ?? true),
                'excluded_venue_ids' => ($venueId !== null && ($input['include_similar_venues'] ?? false)) ? ($input['excluded_venue_ids'] ?? []) : [],
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
                'venue_kind' => ($venueId === null || ($input['include_similar_venues'] ?? false)) ? ($input['venue_kind'] ?? 'any') : 'any',
                'amenity_preferences' => $input['amenity_preferences'] ?? [],
                'notify_matches' => (bool) ($input['notify_matches'] ?? true),
                'notify_offers' => (bool) ($input['notify_offers'] ?? false),
                'status' => $intent->exists && $intent->status === 'paused' ? 'paused' : 'active',
                'expires_at' => $expires,
            ]);
            $intent->save();

            return $intent->refresh();
            });
        } catch (QueryException $exception) {
            // A parallel callback of the same auth/resume request can win the
            // unique (user_id, client_request_id) race. Only swallow that conflict.
            $state = (string) $exception->getCode();
            if ($intent === null && is_string($requestId)
                && in_array($state, ['23000', '23505'], true)) {
                $existing = PlayIntent::query()->where('user_id', $canonical->id)
                    ->where('client_request_id', $requestId)->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            throw $exception;
        }
    }

    /**
     * Restore an expired or cancelled subscription without rewriting its criteria, notifications,
     * public profile preference or immutable client request id. One-time
     * subscriptions must be edited with new dates instead of silently
     * shifting their previously requested game day.
     */
    public function restoreExpired(User $user, PlayIntent $intent): PlayIntent
    {
        $canonical = $user->canonical();
        abort_if($canonical->isBlocked(), 403);
        abort_unless($intent->user_id === $canonical->id, 404);

        return DB::transaction(function () use ($intent): PlayIntent {
            // Lock before rechecking to avoid concurrent restores extending twice.
            $locked = PlayIntent::query()->lockForUpdate()->findOrFail($intent->id);
            $isExpired = $locked->status === 'active'
                && $locked->expires_at !== null && $locked->expires_at->isPast();
            $isCancelled = $locked->status === 'cancelled';
            if (! $isExpired && ! $isCancelled) {
                throw ValidationException::withMessages([
                    'status' => 'Восстановить можно только истёкшую или отменённую подписку.',
                ]);
            }
            if ($locked->cadence === 'once') {
                throw ValidationException::withMessages([
                    'dates' => 'Для восстановления разовой подписки сначала укажи новую дату в редакторе.',
                ]);
            }

            $days = $locked->cadence === 'regular' ? 90 : 30;
            $locked->forceFill([
                'status' => 'active',
                'expires_at' => CarbonImmutable::now()->addDays($days),
            ])->save();

            return $locked->refresh();
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
