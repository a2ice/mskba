<?php

namespace App\Modules\PlayInterest\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\PlayInterest\Domain\Models\VenueGroupOffer;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Carbon;

final class VenueGroupOffers
{
    public function __construct(private readonly VenueCommercialAccess $commercial) {}

    public function canManage(?User $user, Venue $venue): bool
    {
        return $user !== null
            && $venue->allowsOperationalChanges()
            && $user->canonical()->isConfirmed()
            && $this->commercial->allows($user, $venue, VenuePermissionEnum::MANAGE_BOOKING_POLICY);
    }

    public function isMember(?User $user, Venue $venue): bool
    {
        return $user !== null && PlayIntent::query()
            ->whereIn('user_id', $user->canonical()->identityIds())
            ->where('venue_id', $venue->id)
            ->where('status', 'active')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }

    /** Only the authenticated group member or authorized commercial manager can see codes. */
    public function visibleTo(?User $user, Venue $venue)
    {
        $query = VenueGroupOffer::query()
            ->where('venue_id', $venue->id)
            ->where('group_key', VenueGroupOffer::groupKey($venue))
            ->orderByDesc('created_at')->orderByDesc('id');

        if ($this->canManage($user, $venue)) {
            return $query->paginate(10, ['*'], 'offers_page')->withQueryString();
        }

        if (! $this->isMember($user, $venue)) {
            return null;
        }

        return $query->where('status', 'active')
            ->where('valid_from', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))
            ->paginate(10, ['*'], 'offers_page')->withQueryString();
    }

    /** @param array<string, mixed> $values */
    public function create(Venue $venue, User $actor, array $values): VenueGroupOffer
    {
        $code = strtoupper(trim($values['code']));
        $hash = hash('sha256', $code);

        if (VenueGroupOffer::query()->where('code_hash', $hash)->exists()) {
            throw ValidationException::withMessages(['code' => 'Этот промокод уже существует.']);
        }

        $start = Carbon::parse($values['valid_from'], $values['timezone'])->utc();
        $end = ! empty($values['valid_until'])
            ? Carbon::parse($values['valid_until'], $values['timezone'])->utc()
            : null;

        if ($end !== null && $end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages(['valid_until' => 'Окончание должно быть позже начала.']);
        }

        $currency = $venue->bookingPolicyCurrency();
        $discount = (int) $values['discount_value'];
        if ($values['discount_type'] === 'percent' && $discount > 90) {
            throw ValidationException::withMessages(['discount_value' => 'Скидка не может быть больше 90%.']);
        }

        if (isset($values['max_uses_per_account'], $values['max_total_uses'])
            && $values['max_uses_per_account'] > $values['max_total_uses']) {
            throw ValidationException::withMessages(['max_uses_per_account' => 'Лимит на аккаунт превышает общий лимит.']);
        }

        if (isset($values['venue_court_id']) && ! $venue->courts()->whereKey($values['venue_court_id'])->exists()) {
            throw ValidationException::withMessages(['venue_court_id' => 'Зал не принадлежит площадке.']);
        }

        try {
            return DB::transaction(static function () use ($venue, $actor, $values, $hash, $code, $start, $end, $currency, $discount): VenueGroupOffer {
            $offer = VenueGroupOffer::query()->create([
                'venue_id' => $venue->id,
                'group_key' => VenueGroupOffer::groupKey($venue),
                'created_by_user_id' => $actor->canonical()->id,
                'title' => $values['title'],
                'description' => $values['description'] ?? null,
                'code_ciphertext' => $code,
                'code_hash' => $hash,
                'status' => 'draft',
                'discount_type' => $values['discount_type'],
                'discount_value' => $discount,
                'currency' => $currency,
                'scope' => $values['scope'] ?? null,
                'venue_court_id' => $values['venue_court_id'] ?? null,
                'minimum_amount_minor' => $values['minimum_amount_minor'] ?? null,
                'valid_from' => $start,
                'valid_until' => $end,
                'max_total_uses' => $values['max_total_uses'] ?? null,
                'max_uses_per_account' => $values['max_uses_per_account'] ?? null,
                'terms_version' => 1,
            ]);
            $offer->audits()->create([
                'actor_user_id' => $actor->canonical()->id,
                'action' => 'create',
                'previous_status' => null,
                'next_status' => 'draft',
                'terms_version' => 1,
                'created_at' => now(),
            ]);
            return $offer;
            });
        } catch (QueryException $error) {
            // The preliminary exists() check is a usability hint, not a race
            // guard: the unique database index is authoritative.
            if (str_contains($error->getMessage(), 'code_hash')) {
                throw ValidationException::withMessages(['code' => 'Этот промокод уже существует.']);
            }
            throw $error;
        }
    }

    public function transition(VenueGroupOffer $offer, User $actor, string $action): void
    {
        DB::transaction(static function () use ($offer, $actor, $action): void {
            $locked = VenueGroupOffer::query()->lockForUpdate()->findOrFail($offer->id);
            $previous = $locked->status;
            $next = match ($action) {
                'publish' => 'active',
                'pause' => 'paused',
                'revoke' => 'revoked',
                default => null,
            };
            $allowed = match ($action) {
                'publish' => in_array($previous, ['draft', 'paused'], true),
                'pause' => $previous === 'active',
                'revoke' => $previous !== 'revoked',
                default => false,
            };
            if ($next === null || ! $allowed) {
                throw ValidationException::withMessages(['offer' => 'Недопустимое изменение статуса предложения.']);
            }
            if ($action === 'publish' && $locked->valid_until?->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages(['offer' => 'Нельзя опубликовать предложение с истёкшим сроком.']);
            }
            $locked->status = $next;
            $locked->terms_version = $locked->terms_version + 1;
            $locked->save();
            $locked->audits()->create([
                'actor_user_id' => $actor->canonical()->id,
                'action' => $action,
                'previous_status' => $previous,
                'next_status' => $next,
                'terms_version' => $locked->terms_version,
                'created_at' => now(),
            ]);
        });
    }
}
