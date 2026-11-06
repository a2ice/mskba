<?php

namespace App\Modules\VenueBooking\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Venue\Application\Services\EffectiveCourtPolicy;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\Services\VenuePricingRuleEvaluator;
use App\Modules\VenueBooking\Application\Services\VenueCommissionCalculator;
use App\Modules\VenueBooking\Application\Services\VenuePricingRuleManager;
use App\Modules\VenueBooking\Application\Services\VenueSlotPricing;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use App\Presentation\Theming\ThemeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class VenuePricingRuleController extends Controller
{
    public function index(Request $request, Venue $venue, VenueCommercialAccess $access): Response
    {
        $this->authorizeVenue($request, $venue, $access);
        $editing = $request->filled('edit')
            ? $venue->pricingRules()->whereIn('status', ['draft', 'published'])
                ->findOrFail((int) $request->query('edit'))
            : null;

        return ThemeResolver::page('venues.pricing-rules', [
            'venue' => $venue,
            'policy' => VenueBookingPolicy::query()->where('venue_id', $venue->id)
                ->where('active_marker', true)->first(),
            'courts' => $venue->courts()->get(),
            'rules' => $venue->pricingRules()->whereIn('status', ['draft', 'published'])
                ->orderBy('priority')->orderBy('rule_key')->orderByDesc('version')->get(),
            'overrides' => $venue->courtPricingRuleOverrides()->where('is_excluded', true)->get(),
            'editing' => $editing,
        ]);
    }

    public function save(
        Request $request, Venue $venue, VenueCommercialAccess $access,
        VenuePricingRuleManager $manager,
    ): RedirectResponse {
        $this->authorizeVenue($request, $venue, $access);
        $source = $request->filled('rule_id')
            ? $venue->pricingRules()->findOrFail((int) $request->input('rule_id'))
            : null;
        $data = $request->all();
        foreach (['is_enabled', 'stop_processing'] as $key) {
            $data[$key] = $request->boolean($key);
        }
        $draft = $manager->saveDraft($venue, $request->user(), $data, $source);

        return redirect()->route('account.venues.pricing-rules.index', [$venue->id, 'edit' => $draft->id])
            ->with('status', 'Черновик тарифа сохранён. Опубликуйте его после проверки.');
    }

    public function publish(
        Request $request, Venue $venue, int $rule,
        VenueCommercialAccess $access, VenuePricingRuleManager $manager,
    ): RedirectResponse {
        $this->authorizeVenue($request, $venue, $access);
        $manager->publish($venue, $request->user(), $venue->pricingRules()->findOrFail($rule));

        return redirect()->route('account.venues.pricing-rules.index', $venue->id)
            ->with('status', 'Тарифное условие опубликовано.');
    }

    public function destroy(
        Request $request, Venue $venue, int $rule,
        VenueCommercialAccess $access, VenuePricingRuleManager $manager,
    ): RedirectResponse {
        $this->authorizeVenue($request, $venue, $access);
        $manager->deleteDraft($venue, $request->user(), $venue->pricingRules()->findOrFail($rule));

        return redirect()->route('account.venues.pricing-rules.index', $venue->id)
            ->with('status', 'Черновик удалён.');
    }

    public function exclude(
        Request $request, Venue $venue, VenueCommercialAccess $access,
        VenuePricingRuleManager $manager,
    ): RedirectResponse {
        $this->authorizeVenue($request, $venue, $access);
        $input = $request->validate([
            'venue_court_id' => ['required', 'integer'],
            'rule_key' => ['required', 'uuid'],
            'is_excluded' => ['required', 'boolean'],
        ]);
        $court = $venue->courts()->findOrFail((int) $input['venue_court_id']);
        $manager->excludeForCourt($venue, $request->user(), $court, $input['rule_key'], (bool) $input['is_excluded']);

        return redirect()->route('account.venues.pricing-rules.index', $venue->id)
            ->with('status', 'Исключение для зала сохранено.');
    }

    public function preview(
        Request $request, Venue $venue, VenueCommercialAccess $access,
        VenueSlotPricing $pricing, VenuePricingRuleEvaluator $rules,
        EffectiveCourtPolicy $effectivePolicies, VenueCommissionCalculator $commissions,
    ): RedirectResponse {
        $this->authorizeVenue($request, $venue, $access);
        $data = $request->validate([
            'venue_court_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'duration_minutes' => ['required', 'integer', 'between:15,1440'],
            'scope' => ['required', Rule::in(['whole', 'half_a', 'half_b'])],
        ]);
        $court = $venue->courts()->findOrFail((int) $data['venue_court_id']);
        abort_unless($access->allowsForCourt(
            $request->user(), $court, VenuePermissionEnum::MANAGE_BOOKING_POLICY,
        ), 403);

        $policy = VenueBookingPolicy::query()->where('venue_id', $venue->id)
            ->where('active_marker', true)->first();
        if ($policy === null) {
            throw ValidationException::withMessages(['starts_at' => 'Сначала опубликуйте базовые условия аренды.']);
        }
        $effective = $effectivePolicies->resolve($venue, $court, $policy);
        $step = (int) $effective['time_step_minutes'];
        $duration = (int) $data['duration_minutes'];
        $localStart = CarbonImmutable::createFromFormat(
            '!Y-m-d\TH:i', $data['starts_at'],
            $venue->schedule()->value('timezone') ?: 'Europe/Moscow',
        );
        if (! $localStart || $localStart->format('Y-m-d\TH:i') !== $data['starts_at']
            || $duration < (int) $effective['minimum_duration_minutes']
            || $duration > (int) $effective['maximum_duration_minutes']
            || $step < 1 || $duration % $step !== 0
            || ($localStart->hour * 60 + $localStart->minute) % $step !== 0) {
            throw ValidationException::withMessages(['starts_at' => 'Проверьте начало и длительность аренды.']);
        }

        $scope = VenueBookingScopeEnum::from($data['scope']);
        if ($scope === VenueBookingScopeEnum::WHOLE ? ! $court->allows_whole
            : (! $court->supports_halves || ! $court->allows_halves)) {
            throw ValidationException::withMessages(['scope' => 'Выбранный формат аренды недоступен для зала.']);
        }
        $slots = $pricing->calculate($venue, $policy, $localStart, $duration, $scope, $court, $effective);
        $result = $rules->evaluate($venue, $court, $localStart, $duration, $step, $scope, $slots['amount_minor']);
        $commission = $commissions->calculate($venue, $court, $result['total_minor'], $policy->currency);

        return redirect()->route('account.venues.pricing-rules.index', $venue->id)
            ->with('rule_preview', [
                ...$result,
                'currency' => $policy->currency,
                'court_name' => $court->name,
                'starts_at' => $localStart->format('d.m.Y H:i'),
                'duration_minutes' => $duration,
            ]);
    }

    private function authorizeVenue(Request $request, Venue $venue, VenueCommercialAccess $access): void
    {
        abort_unless($request->user() !== null
            && $access->allows($request->user(), $venue, VenuePermissionEnum::MANAGE_BOOKING_POLICY), 403);
    }
}
