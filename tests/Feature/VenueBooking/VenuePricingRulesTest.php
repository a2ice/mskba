<?php

namespace Tests\Feature\VenueBooking;

use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\Venue\Domain\Models\VenuePricingRule;
use App\Modules\VenueBooking\Application\Services\VenuePricingRuleEvaluator;
use App\Modules\VenueBooking\Application\Services\VenuePricingRuleManager;
use App\Modules\VenueBooking\Application\UseCases\QuoteVenueBookingHandler;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingPolicyException;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use App\Modules\VenueBooking\Domain\Models\VenueBookingQuote;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

final class VenuePricingRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.rental_flow', true);
        Carbon::setTestNow(CarbonImmutable::parse('2026-08-25 10:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_published_30_percent_discount_only_applies_from_two_hours(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $manager = app(VenuePricingRuleManager::class);
        $draft = $manager->saveDraft($venue, $admin, $this->formData());

        $before = $this->quote($venue, $court, 120);
        $this->assertSame(2000, $before->amountMinor);
        $manager->publish($venue, $admin, $draft);

        $twoHours = $this->quote($venue, $court, 120);
        $ninetyMinutes = $this->quote($venue, $court, 90);
        $this->assertSame(1400, $twoHours->amountMinor);
        $this->assertSame(1500, $ninetyMinutes->amountMinor);

        $stored = VenueBookingQuote::query()->where('public_id', $twoHours->publicId)->firstOrFail();
        $this->assertSame(2000, $stored->snapshot['pricing']['base_amount_minor']);
        $this->assertSame(1400, $stored->snapshot['pricing']['amount_minor']);
        $this->assertSame('apply_published_rules(sum(prices_per_step_minor))', $stored->snapshot['pricing']['formula']);
        $this->assertSame(1, $stored->snapshot['pricing']['rule_adjustments'][0]['version']);
        $this->assertSame(-600, $stored->snapshot['pricing']['rule_adjustments'][0]['delta_minor']);

        $old = VenueBookingQuote::query()->where('public_id', $before->publicId)->firstOrFail();
        $this->assertSame(2000, $old->refresh()->amount_minor);
        $this->assertSame([], $old->snapshot['pricing']['rule_adjustments']);
    }

    public function test_drafts_have_no_effect_and_new_publication_preserves_old_snapshot(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $manager = app(VenuePricingRuleManager::class);
        $first = $manager->saveDraft($venue, $admin, $this->formData());
        $manager->publish($venue, $admin, $first);
        $firstQuote = $this->quote($venue, $court, 120);

        $draft = $manager->saveDraft($venue, $admin, $this->formData([
            'name' => 'Два часа — скидка 10%',
            'value_text' => '10',
        ]), $first);
        $this->assertSame(2, $draft->version);
        $this->assertSame('draft', $draft->status);
        $this->assertSame(1400, $this->quote($venue, $court, 120)->amountMinor);

        $manager->publish($venue, $admin, $draft);
        $this->assertSame('archived', $first->refresh()->status);
        $this->assertSame('published', $draft->refresh()->status);
        $this->assertSame(1800, $this->quote($venue, $court, 120)->amountMinor);
        $old = VenueBookingQuote::query()->where('public_id', $firstQuote->publicId)->firstOrFail();
        $this->assertSame(1400, $old->amount_minor);
        $this->assertSame(1, $old->snapshot['pricing']['rule_adjustments'][0]['version']);

        $this->expectException(LogicException::class);
        $draft->update(['value' => 9999]);
    }

    public function test_court_inherits_rules_but_can_exclude_parent_and_add_own_rule(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $second = $venue->courts()->create([
            'name' => 'Зал 2', 'alias' => 'zal-2', 'sort_order' => 20,
            'supports_halves' => false, 'allows_whole' => true, 'allows_halves' => false,
        ]);
        $manager = app(VenuePricingRuleManager::class);
        $parent = $manager->publish($venue, $admin,
            $manager->saveDraft($venue, $admin, $this->formData())
        );
        $custom = $manager->publish($venue, $admin,
            $manager->saveDraft($venue, $admin, $this->formData([
                'venue_court_id' => $second->id,
                'name' => 'Доплата за зал 2',
                'priority' => 150,
                'min_duration_minutes' => null,
                'operation' => 'fixed_surcharge',
                'value_text' => '2',
            ]))
        );

        $this->assertSame(1600, $this->quote($venue, $second, 120)->amountMinor);
        $this->assertSame(1400, $this->quote($venue, $court, 120)->amountMinor);
        $manager->excludeForCourt($venue, $admin, $second, $parent->rule_key, true);
        $this->assertSame(2200, $this->quote($venue, $second, 120)->amountMinor);
        $this->assertSame(1400, $this->quote($venue, $court, 120)->amountMinor);
        $manager->excludeForCourt($venue, $admin, $second, $parent->rule_key, false);
        $this->assertSame(1600, $this->quote($venue, $second, 120)->amountMinor);
        $this->assertSame('published', $custom->refresh()->status);
    }

    public function test_stop_processing_and_rule_priority_are_deterministic(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $manager = app(VenuePricingRuleManager::class);
        $manager->publish($venue, $admin, $manager->saveDraft(
            $venue, $admin, $this->formData(['priority' => 10, 'stop_processing' => true])
        ));
        $manager->publish($venue, $admin, $manager->saveDraft(
            $venue, $admin, $this->formData([
                'name' => 'Надбавка', 'priority' => 20,
                'operation' => 'fixed_surcharge', 'value_text' => '3',
            ])
        ));
        $quote = $this->quote($venue, $court, 120);
        $this->assertSame(1400, $quote->amountMinor);
        $snap = VenueBookingQuote::query()->where('public_id', $quote->publicId)->firstOrFail()->snapshot;
        $this->assertCount(1, $snap['pricing']['rule_adjustments']);
    }

    public function test_weekday_date_scope_time_and_amount_conditions(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $manager = app(VenuePricingRuleManager::class);
        $manager->publish($venue, $admin, $manager->saveDraft($venue, $admin, $this->formData([
            'weekdays' => [3],
            'date_from' => '2026-08-26',
            'date_to' => '2026-08-30',
            'time_from' => '12:00',
            'time_to' => '14:00',
            'scope' => 'whole',
            'min_base_minor' => 2000,
        ])));
        $eval = app(VenuePricingRuleEvaluator::class);
        $start = CarbonImmutable::parse('2026-08-26 12:00:00', 'Europe/Moscow');
        $whole = $eval->evaluate($venue, $court, $start, 120, 30, VenueBookingScopeEnum::WHOLE, 2000);
        $this->assertSame(1400, $whole['total_minor']);
        $this->assertSame(2000, $eval->evaluate(
            $venue, $court, $start, 120, 30, VenueBookingScopeEnum::HALF_A, 2000
        )['total_minor']);
        $this->assertSame(2000, $eval->evaluate(
            $venue, $court, $start->addHour(), 120, 30, VenueBookingScopeEnum::WHOLE, 2000
        )['total_minor']);
        $this->assertSame(2000, $eval->evaluate(
            $venue, $court, $start->addDay(), 120, 30, VenueBookingScopeEnum::WHOLE, 2000
        )['total_minor']);
        $this->assertSame(1999, $eval->evaluate(
            $venue, $court, $start, 120, 30, VenueBookingScopeEnum::WHOLE, 1999
        )['total_minor']);
    }

    public function test_integer_rounding_clamping_and_fixed_formula(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $manager = app(VenuePricingRuleManager::class);
        $rule = $manager->publish($venue, $admin, $manager->saveDraft(
            $venue, $admin, $this->formData(['min_duration_minutes' => null, 'value_text' => '10'])
        ));
        $eval = app(VenuePricingRuleEvaluator::class);
        $start = CarbonImmutable::parse('2026-08-26 12:00:00', 'Europe/Moscow');
        $this->assertSame(4, $eval->evaluate(
            $venue, $court, $start, 60, 30, VenueBookingScopeEnum::WHOLE, 5
        )['total_minor']); // half-up: 0.5 minor unit => 1

        $draft = $manager->saveDraft($venue, $admin, $this->formData([
            'min_duration_minutes' => null, 'operation' => 'fixed_total',
            'value_text' => '7,50',
        ]), $rule);
        $manager->publish($venue, $admin, $draft);
        $this->assertSame(750, $eval->evaluate(
            $venue, $court, $start, 60, 30, VenueBookingScopeEnum::WHOLE, 500
        )['total_minor']);
    }

    public function test_paid_quote_rejects_a_rule_that_reduces_total_to_zero(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $manager = app(VenuePricingRuleManager::class);
        $manager->publish($venue, $admin, $manager->saveDraft($venue, $admin, $this->formData([
            'operation' => 'percent_discount', 'value_text' => '100',
        ])));
        $this->expectException(VenueBookingPolicyException::class);
        $this->quote($venue, $court, 120);
    }

    public function test_rule_input_rejects_untrusted_code_and_invalid_periods(): void
    {
        [$admin, $venue] = $this->scenario();
        $manager = app(VenuePricingRuleManager::class);
        foreach ([
            ['value_text' => 'system("id")'],
            ['date_from' => '2026-09-10', 'date_to' => '2026-09-01'],
            ['time_from' => '18:00', 'time_to' => '09:00'],
            ['min_duration_minutes' => 240, 'max_duration_minutes' => 120],
            ['operation' => 'eval'],
        ] as $bad) {
            try {
                $manager->saveDraft($venue, $admin, $this->formData($bad));
                $this->fail('Invalid rule must never persist.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, $venue->pricingRules()->count());
    }

    public function test_http_constructor_permissions_publishing_and_preview_without_booking(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        config()->set('themes.active', 'mskba_app');
        \Illuminate\Support\Facades\View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));

        $this->actingAs($admin)->get(route('account.venues.pricing-rules.index', $venue->id))
            ->assertOk()->assertSeeText('Тарифные условия')->assertSeeText('Проверка расчёта');

        $this->actingAs($admin)->post(route('account.venues.pricing-rules.save', $venue->id), $this->formData())
            ->assertRedirect()->assertSessionHasNoErrors();

        $draft = $venue->pricingRules()->firstOrFail();
        $this->assertSame('draft', $draft->status);
        $this->actingAs($admin)->post(route('account.venues.pricing-rules.publish', [$venue->id, $draft->id]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('account.venues.pricing-rules.preview', $venue->id), [
            'venue_court_id' => $court->id, 'starts_at' => '2026-08-26T12:00',
            'duration_minutes' => 120, 'scope' => 'whole',
        ])->assertRedirect()->assertSessionHas('rule_preview.total_minor', 1400);
        $this->assertSame(0, VenueBookingQuote::query()->count());

        $other = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($other)->get(route('account.venues.pricing-rules.index', $venue->id))
            ->assertForbidden();
        $this->actingAs($other)->post(route('account.venues.pricing-rules.save', $venue->id), $this->formData())
            ->assertForbidden();
        $this->actingAs($other)->post(route('account.venues.pricing-rules.publish', [$venue->id, $draft->id]))
            ->assertForbidden();
    }

    public function test_only_draft_may_be_deleted_and_published_state_is_preserved(): void
    {
        [$admin, $venue] = $this->scenario();
        $manager = app(VenuePricingRuleManager::class);
        $draft = $manager->saveDraft($venue, $admin, $this->formData());
        $manager->deleteDraft($venue, $admin, $draft);
        $this->assertDatabaseMissing('venue_pricing_rules', ['id' => $draft->id]);

        $published = $manager->publish($venue, $admin,
            $manager->saveDraft($venue, $admin, $this->formData())
        );
        $this->expectException(ValidationException::class);
        $manager->deleteDraft($venue, $admin, $published);
    }

    /** @return array{User,Venue,VenueCourt} */
    private function scenario(): array
    {
        $admin = User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'operational_status' => VenueOperationalStatusEnum::ACTIVE,
        ]);
        $venue->schedule()->create(['timezone' => 'Europe/Moscow']);
        $court = $venue->primaryCourt()->firstOrFail();
        VenueBookingPolicy::query()->create([
            'venue_id' => $venue->id,
            'version' => 1, 'active_marker' => true, 'is_enabled' => true,
            'allows_whole' => true, 'allows_halves' => false,
            'minimum_duration_minutes' => 60, 'maximum_duration_minutes' => 240,
            'time_step_minutes' => 30, 'minimum_lead_time_minutes' => 0,
            'maximum_advance_days' => 90, 'currency' => 'RUB',
            'whole_price_per_step_minor' => 500, 'half_price_per_step_minor' => 300,
            'hold_duration_minutes' => 30, 'requires_payment' => true,
            'payment_window_minutes' => 30, 'quote_validity_minutes' => 15,
            'published_by_user_id' => $admin->id, 'published_at' => now(),
        ]);

        return [$admin, $venue, $court];
    }

    private function quote(Venue $venue, VenueCourt $court, int $minutes)
    {
        return app(QuoteVenueBookingHandler::class)->handle(
            $venue,
            CarbonImmutable::parse('2026-08-26 12:00:00', 'Europe/Moscow'),
            $minutes, VenueBookingScopeEnum::WHOLE, court: $court,
        );
    }

    /** @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function formData(array $overrides = []): array
    {
        return [
            'name' => 'Два часа — скидка 30%',
            'description' => 'Скидка при аренде двух часов',
            'venue_court_id' => null,
            'priority' => 100, 'is_enabled' => true, 'stop_processing' => false,
            'scope' => 'all', 'min_duration_minutes' => 120,
            'operation' => 'percent_discount', 'value_text' => '30',
            ...$overrides,
        ];
    }
}
