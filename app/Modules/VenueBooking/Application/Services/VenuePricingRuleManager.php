<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\Venue\Domain\Models\VenueCourtPricingRuleOverride;
use App\Modules\Venue\Domain\Models\VenuePricingRule;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class VenuePricingRuleManager
{
    public function __construct(
        private VenueCommercialAccess $access,
        private MinorAmountParser $amounts,
    ) {}

    /** @param array<string,mixed> $input */
    public function saveDraft(Venue $venue, User $user, array $input, ?VenuePricingRule $source = null): VenuePricingRule
    {
        $this->authorize($venue, $user, $source?->venue_court_id);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'venue_court_id' => ['nullable', 'integer'],
            'priority' => ['required', 'integer', 'between:0,1000'],
            'is_enabled' => ['required', 'boolean'],
            'stop_processing' => ['required', 'boolean'],
            'scope' => ['required', Rule::in(['all', 'whole', 'half'])],
            'min_duration_minutes' => ['nullable', 'integer', 'between:1,1440'],
            'max_duration_minutes' => ['nullable', 'integer', 'between:1,1440'],
            'min_steps' => ['nullable', 'integer', 'between:1,288'],
            'weekdays' => ['nullable', 'array', 'max:7'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'time_from' => ['nullable', 'date_format:H:i'],
            'time_to' => ['nullable', 'date_format:H:i'],
            'min_base_minor' => ['nullable', 'integer', 'between:0,1000000000000'],
            'operation' => ['required', Rule::in([
                'percent_discount', 'fixed_discount', 'percent_surcharge',
                'fixed_surcharge', 'fixed_total', 'minimum_total',
            ])],
            'value_text' => ['required', 'string', 'max:32'],
        ])->validate();

        if (($validated['min_duration_minutes'] ?? 0) > 0
            && ($validated['max_duration_minutes'] ?? 1440) < $validated['min_duration_minutes']) {
            throw ValidationException::withMessages(['max_duration_minutes' => 'Максимальная длительность меньше минимальной.']);
        }
        if (isset($validated['date_from'], $validated['date_to'])
            && $validated['date_to'] < $validated['date_from']) {
            throw ValidationException::withMessages(['date_to' => 'Конец периода должен быть не раньше начала.']);
        }
        if (isset($validated['time_from']) xor isset($validated['time_to'])) {
            throw ValidationException::withMessages(['time_to' => 'Укажите обе границы временного интервала.']);
        }
        if (isset($validated['time_from'], $validated['time_to'])
            && $validated['time_from'] >= $validated['time_to']) {
            throw ValidationException::withMessages(['time_to' => 'Конец интервала должен быть позже начала.']);
        }

        $courtId = empty($validated['venue_court_id']) ? null : (int) $validated['venue_court_id'];
        if ($source !== null) {
            abort_unless((int) $source->venue_id === (int) $venue->id, 404);
            // A revision cannot silently change its scope or the physical court.
            if ($courtId !== $source->venue_court_id) {
                throw ValidationException::withMessages(['venue_court_id' => 'Для другой области создайте отдельное правило.']);
            }
        }
        $this->authorize($venue, $user, $courtId);

        $conditions = [
            'scope' => $validated['scope'],
            'min_duration_minutes' => $validated['min_duration_minutes'] ?? null,
            'max_duration_minutes' => $validated['max_duration_minutes'] ?? null,
            'min_steps' => $validated['min_steps'] ?? null,
            'weekdays' => collect($validated['weekdays'] ?? [])->map(fn ($n): int => (int) $n)
                ->unique()->sort()->values()->all(),
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'time_from' => $validated['time_from'] ?? null,
            'time_to' => $validated['time_to'] ?? null,
            'min_base_minor' => $validated['min_base_minor'] ?? null,
        ];

        $operation = $validated['operation'];
        if (str_starts_with($operation, 'percent_')) {
            $value = $this->basisPoints($validated['value_text']);
            $max = $operation === 'percent_discount' ? 10000 : 50000;
            if ($value > $max) {
                throw ValidationException::withMessages(['value_text' => 'Процент превышает допустимое значение.']);
            }
        } else {
            try {
                $value = $this->amounts->parse($validated['value_text'], $venue->bookingPolicyCurrency());
            } catch (VenueBookingTransitionException $exception) {
                throw ValidationException::withMessages(['value_text' => $exception->getMessage()]);
            }
            if ($value > 1000000000000) {
                throw ValidationException::withMessages(['value_text' => 'Сумма слишком велика.']);
            }
        }

        return DB::transaction(function () use ($venue, $user, $source, $courtId, $validated, $conditions, $operation, $value): VenuePricingRule {
            Venue::query()->whereKey($venue->id)->lockForUpdate()->firstOrFail();
            $draft = null;
            if ($source !== null) {
                $draft = VenuePricingRule::query()
                    ->where('venue_id', $venue->id)->where('rule_key', $source->rule_key)
                    ->where('status', 'draft')->lockForUpdate()->first();
            }

            if ($draft === null) {
                $version = $source === null ? 1 : 1 + (int) VenuePricingRule::query()
                    ->where('venue_id', $venue->id)->where('rule_key', $source->rule_key)->max('version');
                $draft = new VenuePricingRule([
                    'venue_id' => $venue->id,
                    'venue_court_id' => $courtId,
                    'rule_key' => $source?->rule_key ?? (string) Str::uuid(),
                    'version' => $version,
                    'status' => 'draft',
                ]);
            }

            $draft->fill([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'priority' => (int) $validated['priority'],
                'is_enabled' => (bool) $validated['is_enabled'],
                'stop_processing' => (bool) $validated['stop_processing'],
                'conditions' => $conditions,
                'operation' => $operation,
                'value' => $value,
                'funding_source' => 'venue',
            ]);
            $draft->save();

            return $draft;
        });
    }

    public function publish(Venue $venue, User $user, VenuePricingRule $draft): VenuePricingRule
    {
        abort_unless((int) $draft->venue_id === (int) $venue->id, 404);
        $this->authorize($venue, $user, $draft->venue_court_id);

        return DB::transaction(function () use ($venue, $user, $draft): VenuePricingRule {
            Venue::query()->whereKey($venue->id)->lockForUpdate()->firstOrFail();
            $draft = VenuePricingRule::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if ($draft->status !== 'draft') {
                throw ValidationException::withMessages(['rule' => 'Опубликовать можно только черновик.']);
            }
            $prior = VenuePricingRule::query()
                ->where('venue_id', $venue->id)
                ->where('rule_key', $draft->rule_key)
                ->where('status', 'published')->lockForUpdate()->get();
            foreach ($prior as $published) {
                $published->update(['status' => 'archived']);
            }
            $draft->update([
                'status' => 'published',
                'published_by_user_id' => $user->canonical()->id,
                'published_at' => now(),
            ]);

            return $draft->refresh();
        }, 3);
    }

    public function deleteDraft(Venue $venue, User $user, VenuePricingRule $draft): void
    {
        abort_unless((int) $draft->venue_id === (int) $venue->id, 404);
        $this->authorize($venue, $user, $draft->venue_court_id);
        if ($draft->status !== 'draft') {
            throw ValidationException::withMessages(['rule' => 'Удалять опубликованное правило нельзя.']);
        }
        $draft->delete();
    }

    public function excludeForCourt(
        Venue $venue, User $user, VenueCourt $court, string $ruleKey, bool $exclude,
    ): void {
        abort_unless((int) $court->venue_id === (int) $venue->id, 404);
        $this->authorize($venue, $user, $court->id);
        $rule = VenuePricingRule::query()
            ->where('venue_id', $venue->id)->whereNull('venue_court_id')
            ->where('rule_key', $ruleKey)->where('status', 'published')->firstOrFail();
        VenueCourtPricingRuleOverride::query()->updateOrCreate([
            'venue_id' => $venue->id,
            'venue_court_id' => $court->id,
            'rule_key' => $rule->rule_key,
        ], ['is_excluded' => $exclude]);
    }

    private function authorize(Venue $venue, User $user, ?int $courtId): void
    {
        abort_unless($this->access->allows($user, $venue, VenuePermissionEnum::MANAGE_BOOKING_POLICY), 403);
        if ($courtId !== null) {
            $court = $venue->courts()->findOrFail($courtId);
            abort_unless($this->access->allowsForCourt($user, $court, VenuePermissionEnum::MANAGE_BOOKING_POLICY), 403);
        }
    }

    private function basisPoints(string $input): int
    {
        $clean = str_replace(',', '.', trim($input));
        if (! preg_match('/^([0-9]{1,3})(?:\.([0-9]{1,2}))?$/D', $clean, $matches)) {
            throw ValidationException::withMessages(['value_text' => 'Укажите процент от 0 до 100 с точностью 0,01.']);
        }

        return (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
    }
}
