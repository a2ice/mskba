<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\Venue\Domain\Models\VenueCourtPricingRuleOverride;
use App\Modules\Venue\Domain\Models\VenuePricingRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class VenuePricingRuleEvaluator
{
    /**
     * Evaluates published, enabled rules only; does not mutate a rule or a quote.
     * Deterministic precedence: priority ascending, venue before court, rule ID ascending.
     * All math uses currency minor units and integer basis points.
     *
     * @return array{base_minor:int,total_minor:int,adjustments:list<array<string,mixed>>}
     */
    public function evaluate(
        Venue $venue, VenueCourt $court, CarbonImmutable $localStart,
        int $durationMinutes, int $stepMinutes, VenueBookingScopeEnum $scope,
        int $baseMinor,
    ): array {
        if ($baseMinor <= 0) {
            return ['base_minor' => $baseMinor, 'total_minor' => $baseMinor, 'adjustments' => []];
        }
        $excluded = VenueCourtPricingRuleOverride::query()
            ->where('venue_id', $venue->id)->where('venue_court_id', $court->id)
            ->where('is_excluded', true)->pluck('rule_key')->all();

        $rules = VenuePricingRule::query()
            ->where('venue_id', $venue->id)->where('status', 'published')
            ->where('is_enabled', true)
            ->where(function ($query) use ($court): void {
                $query->whereNull('venue_court_id')->orWhere('venue_court_id', $court->id);
            })
            ->orderBy('priority')->orderByRaw('venue_court_id IS NOT NULL ASC')
            ->orderBy('id')->get();

        $total = $baseMinor;
        $adjustments = [];
        foreach ($rules as $rule) {
            if ($rule->venue_court_id === null && in_array($rule->rule_key, $excluded, true)) {
                continue;
            }
            if (! $this->matches($rule, $localStart, $durationMinutes, $stepMinutes, $scope, $baseMinor)) {
                continue;
            }

            $before = $total;
            $total = $this->apply($rule, $total);
            $adjustments[] = [
                'rule_id' => $rule->id,
                'rule_key' => $rule->rule_key,
                'version' => $rule->version,
                'name' => $rule->name,
                'origin' => $rule->venue_court_id === null ? 'venue' : 'court',
                'operation' => $rule->operation,
                'value' => $rule->value,
                'funding_source' => $rule->funding_source,
                'priority' => $rule->priority,
                'before_minor' => $before,
                'after_minor' => $total,
                'delta_minor' => $total - $before,
            ];
            if ($rule->stop_processing) {
                break;
            }
        }

        return ['base_minor' => $baseMinor, 'total_minor' => $total, 'adjustments' => $adjustments];
    }

    private function matches(
        VenuePricingRule $rule, CarbonImmutable $start, int $duration,
        int $step, VenueBookingScopeEnum $scope, int $base,
    ): bool {
        $c = $rule->conditions ?? [];
        $kind = $scope === VenueBookingScopeEnum::WHOLE ? 'whole' : 'half';
        if (($c['scope'] ?? 'all') !== 'all' && $c['scope'] !== $kind) {
            return false;
        }
        if ($duration < (int) ($c['min_duration_minutes'] ?? 0)
            || ($c['max_duration_minutes'] ?? null) !== null
                && $duration > (int) $c['max_duration_minutes']) {
            return false;
        }
        if ($step <= 0 || ($c['min_steps'] ?? null) !== null
            && intdiv($duration, $step) < (int) $c['min_steps']) {
            return false;
        }
        if (($c['min_base_minor'] ?? null) !== null && $base < (int) $c['min_base_minor']) {
            return false;
        }
        if (($c['weekdays'] ?? []) !== [] && ! in_array($start->dayOfWeekIso, $c['weekdays'], true)) {
            return false;
        }
        $date = $start->toDateString();
        if (($c['date_from'] ?? null) !== null && $date < $c['date_from']) {
            return false;
        }
        if (($c['date_to'] ?? null) !== null && $date > $c['date_to']) {
            return false;
        }
        if (($c['time_from'] ?? null) !== null) {
            $end = $start->addMinutes($duration);
            // A price rule's daily time window never implicitly spans midnight.
            if ($end->toDateString() !== $date
                || $start->format('H:i') < $c['time_from']
                || $end->format('H:i') > $c['time_to']) {
                return false;
            }
        }

        return true;
    }

    private function apply(VenuePricingRule $rule, int $current): int
    {
        $value = (int) $rule->value;
        return match ($rule->operation) {
            'percent_discount' => max(0, $current - intdiv($current * $value + 5000, 10000)),
            'fixed_discount' => max(0, $current - $value),
            'percent_surcharge' => min(1000000000000, $current + intdiv($current * $value + 5000, 10000)),
            'fixed_surcharge' => min(1000000000000, $current + $value),
            'fixed_total' => $value,
            'minimum_total' => max($current, $value),
            default => $current,
        };
    }
}
