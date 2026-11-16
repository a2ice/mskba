<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Application\Services\VenueGroupOffers;
use App\Modules\PlayInterest\Domain\Models\VenueGroupOffer;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Immutable terms: change a price by revoking and issuing a new offer. */
final class VenueSpecialTermsManager
{
    public function canManage(?User $user, Venue $venue, bool $administration = false): bool
    {
        if ($user === null || ! $venue->allowsOperationalChanges() || ! $user->canonical()->isConfirmed()) {
            return false;
        }
        $memberships = app(VenueMembershipAccess::class);
        $owner = $memberships->activeVenueMembershipsFor($user->canonical())
            ->contains(fn ($membership) => $membership->scope_type->value === 'venue'
                && (int) $membership->scope_id === (int) $venue->id
                && $membership->access_level === VenueMembershipAccessLevelEnum::OWNER->value);

        return $owner || $memberships->allows($user, $venue, VenuePermissionEnum::MANAGE_SPECIAL_TERMS)
            || ($administration && $user->canonical()->hasSystemRole(UserSystemRoleEnum::SUPERADMIN));
    }

    public function create(Venue $venue, User $actor, array $input, bool $administration = false): VenueGroupOffer
    {
        abort_unless($this->canManage($actor, $venue, $administration), 403);
        foreach (['time_from', 'time_to'] as $key) {
            if (isset($input[$key]) && is_string($input[$key]) && preg_match('/^\d{2}:\d{2}:00$/D', $input[$key])) {
                $input[$key] = substr($input[$key], 0, 5);
            }
        }
        $data = Validator::make($input, [
            'funded_by' => ['prohibited'],
            'conversation_id' => ['prohibited'],
            'event_id' => ['prohibited'],
            'booking_id' => ['prohibited'],
            'stacking_policy' => ['prohibited'],
            'title' => ['required', 'string', 'min:3', 'max:120'],
            'description' => ['required', 'string', 'max:1000'],
            'audience' => ['required', Rule::in(['PUBLIC', 'USER_BOUND'])],
            'auto_apply' => ['sometimes', 'boolean'],
            'recipient_ids' => ['required_if:audience,USER_BOUND', 'array', 'max:100'],
            'recipient_ids.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'code' => ['nullable', 'required_if:audience,PUBLIC', 'string', 'min:5', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'discount_type' => ['required', Rule::in(['percent', 'fixed', 'fixed_total'])],
            'discount_value' => ['required', 'integer', 'between:1,100000000'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'scope' => ['nullable', Rule::in(['whole', 'half_a', 'half_b'])],
            'venue_court_id' => ['nullable', 'integer', Rule::exists('venue_courts', 'id')->where('venue_id', $venue->id)->whereNull('deleted_at')],
            'minimum_amount_minor' => ['nullable', 'integer', 'between:0,1000000000000'],
            'max_total_uses' => ['nullable', 'integer', 'between:1,1000000'],
            'max_uses_per_account' => ['nullable', 'integer', 'between:1,1000000'],
            'booking_date_from' => ['nullable', 'date_format:Y-m-d'],
            'booking_date_to' => ['nullable', 'date_format:Y-m-d'],
            'time_from' => ['nullable', 'date_format:H:i', 'required_with:time_to'],
            'time_to' => ['nullable', 'date_format:H:i', 'required_with:time_from', 'after:time_from'],
            'weekdays' => ['nullable', 'array', 'max:7'],
            'weekdays.*' => ['integer', 'between:1,7', 'distinct'],
        ])->validate();
        if (! empty($data['booking_date_from']) && ! empty($data['booking_date_to'])
            && $data['booking_date_to'] < $data['booking_date_from']) {
            throw ValidationException::withMessages(['booking_date_to' => 'Окончание периода раньше начала.']);
        }
        $recipients = [];
        foreach ($data['recipient_ids'] ?? [] as $id) {
            $recipient = User::query()->findOrFail($id)->canonical();
            if (! $recipient->isConfirmed()) {
                throw ValidationException::withMessages(['recipient_ids' => 'Выбери подтверждённые аккаунты.']);
            }
            $recipients[] = $recipient->id;
        }
        if ($data['audience'] === 'USER_BOUND' && $recipients === []) {
            throw ValidationException::withMessages(['recipient_ids' => 'Нужен хотя бы один получатель.']);
        }
        if (($data['auto_apply'] ?? false) && $data['audience'] !== 'USER_BOUND') {
            throw ValidationException::withMessages(['auto_apply' => 'Автоматические условия должны быть персональными.']);
        }
        if ($data['discount_type'] === 'percent' && $data['discount_value'] > 99) {
            throw ValidationException::withMessages(['discount_value' => 'Процент должен быть от 1 до 99.']);
        }
        $timezone = $venue->schedule()->value('timezone') ?: config('app.timezone', 'Europe/Moscow');
        $start = empty($data['valid_from']) ? CarbonImmutable::now('UTC') : CarbonImmutable::parse($data['valid_from'], $timezone)->utc();
        $end = empty($data['valid_until']) ? null : CarbonImmutable::parse($data['valid_until'], $timezone)->utc();
        if ($end !== null && $end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages(['valid_until' => 'Окончание должно быть позже начала.']);
        }
        $code = strtoupper(trim($data['code'] ?? '')) ?: strtoupper(Str::random(24));
        try {
            return DB::transaction(function () use ($venue, $actor, $data, $recipients, $code, $start, $end): VenueGroupOffer {
                // The unique hash index is the cross-venue race guard.
                if (VenueGroupOffer::query()->where('code_hash', hash('sha256', $code))->exists()) {
                    throw ValidationException::withMessages(['code' => 'Этот промокод уже существует.']);
                }
                $offer = VenueGroupOffer::query()->create([
                    ...array_intersect_key($data, array_flip(['title', 'description', 'audience', 'auto_apply',
                        'discount_type', 'discount_value', 'scope', 'venue_court_id', 'minimum_amount_minor',
                        'max_total_uses', 'max_uses_per_account'])),
                    'venue_id' => $venue->id, 'group_key' => 'special:'.$venue->id,
                    'created_by_user_id' => $actor->canonical()->id,
                    'code_ciphertext' => $code, 'code_hash' => hash('sha256', $code),
                    'status' => 'draft', 'currency' => $venue->bookingPolicyCurrency(),
                    'valid_from' => $start, 'valid_until' => $end, 'terms_version' => 1,
                    'booking_conditions' => array_intersect_key($data, array_flip([
                        'booking_date_from', 'booking_date_to', 'time_from', 'time_to', 'weekdays',
                    ])),
                ]);
                if ($data['audience'] === 'USER_BOUND') {
                    $offer->recipients()->sync(array_unique($recipients));
                }
                $offer->audits()->create([
                    'actor_user_id' => $actor->canonical()->id, 'action' => 'create',
                    'next_status' => 'draft', 'terms_version' => 1, 'created_at' => now(),
                ]);

                return $offer;
            });
        } catch (QueryException $error) {
            if (str_contains($error->getMessage(), 'code_hash')) {
                throw ValidationException::withMessages(['code' => 'Этот промокод уже существует.']);
            }
            throw $error;
        }
    }

    public function transition(Venue $venue, User $actor, VenueGroupOffer $offer, string $action, bool $administration = false): void
    {
        abort_unless($this->canManage($actor, $venue, $administration), 403);
        abort_unless((int) $offer->venue_id === (int) $venue->id
            && in_array($offer->audience, ['PUBLIC', 'USER_BOUND'], true), 404);
        app(VenueGroupOffers::class)->transition($offer, $actor, $action);
    }
}
