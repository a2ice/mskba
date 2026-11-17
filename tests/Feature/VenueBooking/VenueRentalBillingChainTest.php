<?php

namespace Tests\Feature\VenueBooking;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\Services\VenueBookingActionState;
use App\Modules\VenueBooking\Application\Services\VenueCommissionManager;
use App\Modules\VenueBooking\Application\Services\VenueExternalHoldManager;
use App\Modules\VenueBooking\Application\Services\VenueRentalInvoiceManager;
use App\Modules\VenueBooking\Application\UseCases\AcceptVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\ConfirmVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\QuoteVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\RequestVenueBookingHandler;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use App\Modules\VenueBooking\Domain\Models\VenueRentalInvoiceTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VenueRentalBillingChainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.rental_flow', true);
        config()->set('features.venue_rental.external_holds', true);
        config()->set('features.venue_rental.invoices', true);
        $this->travelTo(CarbonImmutable::parse('2026-08-25 10:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    /** @return array{User,Actor,Venue,User,Actor} */
    private function scenario(bool $agent = false): array
    {
        $admin = User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $adminActor = app(CurrentActorResolver::class)->resolve($admin, null);
        $client = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $clientActor = app(CurrentActorResolver::class)->resolve($client, null);
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'operational_status' => VenueOperationalStatusEnum::ACTIVE,
        ]);
        $venue->schedule()->create(['timezone' => 'Europe/Moscow']);
        VenueBookingPolicy::query()->create([
            'venue_id' => $venue->id,
            'version' => 1,
            'active_marker' => true, 'is_enabled' => true,
            'allows_whole' => true, 'allows_halves' => false,
            'minimum_duration_minutes' => 60, 'maximum_duration_minutes' => 240,
            'time_step_minutes' => 30, 'minimum_lead_time_minutes' => 0,
            'maximum_advance_days' => 90, 'currency' => 'RUB',
            'whole_price_per_step_minor' => 50000, 'half_price_per_step_minor' => 30000,
            'hold_duration_minutes' => 30, 'requires_payment' => true,
            'payment_window_minutes' => 30, 'quote_validity_minutes' => 15,
            'external_hold_required' => true,
            'hold_verification_method' => 'phone', 'hold_verification_note' => 'Подтверждено у администратора',
            'portal_venue_postpay_allowed' => true,
            'published_by_user_id' => $admin->id, 'published_at' => now(),
        ]);
        $contract = Contract::query()->create([
            'family' => ContractFamilyEnum::MEMBERSHIP,
            'status' => ContractStatusEnum::ACTIVE,
            'starts_at' => now()->subMinute(),
            'assigner' => UserParticipationRoleAssignerEnum::OTHER,
        ]);
        $contract->membership()->create([
            'scope_type' => ContractMembershipScopeTypeEnum::VENUE,
            'scope_id' => $venue->id, 'user_id' => $admin->id,
            'access_level' => 'owner',
        ]);
        if ($agent) {
            $agentUser = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
            $delegation = Contract::query()->create([
                'family' => ContractFamilyEnum::MEMBERSHIP,
                'status' => ContractStatusEnum::ACTIVE,
                'starts_at' => now()->subMinute(),
                'assigner' => UserParticipationRoleAssignerEnum::OTHER,
            ]);
            $delegation->membership()->create([
                'scope_type' => ContractMembershipScopeTypeEnum::VENUE,
                'scope_id' => $venue->id, 'user_id' => $agentUser->id,
                'access_level' => 'responsible',
            ]);
            $delegation->permissions()->createMany([
                ['permission' => VenuePermissionEnum::VIEW_BOOKING_REQUESTS->value],
                ['permission' => VenuePermissionEnum::CONFIRM_BOOKING_REQUESTS->value],
                ['permission' => VenuePermissionEnum::CONFIRM_PAYMENTS->value],
            ]);
            $manager = app(VenueCommissionManager::class);
            $manager->setPortal($admin, 'percent', '10');
            $assignment = $manager->saveAgent($venue, $admin, $agentUser, 'percent', '5');
            $manager->setVenue($venue, $admin, 'inherit', selectedAgentId: $assignment->id);
        }

        return [$admin, $adminActor, $venue, $client, $clientActor];
    }

    private function requested(Venue $venue, User $client, Actor $actor): VenueBooking
    {
        $quote = app(QuoteVenueBookingHandler::class)->handle(
            $venue, CarbonImmutable::parse('2026-08-26 12:00', 'Europe/Moscow'),
            120, VenueBookingScopeEnum::WHOLE, $client,
            $venue->primaryCourt()->firstOrFail(),
        );

        return app(RequestVenueBookingHandler::class)->handle($actor, $quote->publicId);
    }

    public function test_external_hold_is_confirmed_before_the_mskba_hold_and_capped_by_policy(): void
    {
        [, $adminActor, $venue, $client, $clientActor] = $this->scenario();
        $booking = $this->requested($venue, $client, $clientActor);
        $this->assertSame(VenueBookingStatusEnum::REQUESTED, $booking->status);
        $this->assertDatabaseCount('venue_booking_external_holds', 0);

        try {
            app(AcceptVenueBookingHandler::class)->handle($booking->id, $adminActor);
            $this->fail('An external venue must not be HELD without evidence.');
        } catch (VenueBookingTransitionException $exception) {
            $this->assertSame('EXTERNAL_HOLD_REQUIRED', $exception->errorCode);
        }

        try {
            app(VenueExternalHoldManager::class)->confirm(
                $booking->id, $adminActor, CarbonImmutable::now()->addMinutes(40),
                'phone', null, 'Согласовано по телефону',
            );
            $this->fail('External hold cannot exceed the single venue policy limit.');
        } catch (VenueBookingTransitionException $exception) {
            $this->assertSame('EXTERNAL_HOLD_DEADLINE', $exception->errorCode);
        }

        $held = app(VenueExternalHoldManager::class)->confirm(
            $booking->id, $adminActor, CarbonImmutable::now()->addMinutes(20),
            'phone', 'EXT-123', 'Администратор подтвердил по телефону',
        );
        $this->assertSame(VenueBookingStatusEnum::HELD, $held->status);
        $this->assertSame(
            CarbonImmutable::now()->addMinutes(20)->getTimestamp(),
            $held->hold_expires_at->getTimestamp(),
        );
        $this->assertDatabaseHas('venue_booking_external_holds', [
            'venue_booking_id' => $booking->id, 'method' => 'phone',
        ]);
        $this->assertFalse(app(VenueBookingActionState::class)->for($booking->fresh(), $clientActor)['confirm']['allowed']);
    }

    public function test_without_agent_all_payments_pass_through_portal_and_postpay_is_not_paid(): void
    {
        [, $adminActor, $venue, $client, $clientActor] = $this->scenario();
        $booking = $this->requested($venue, $client, $clientActor);
        app(VenueExternalHoldManager::class)->confirm(
            $booking->id, $adminActor, CarbonImmutable::now()->addMinutes(25),
            'messenger', null, 'Реальная площадка подтвердили холд',
        );
        $invoices = app(VenueRentalInvoiceManager::class);
        $buyer = $invoices->issueBuyer($booking->id, $adminActor);
        $this->assertSame('portal', $buyer->issuer_kind);
        $this->assertSame('buyer', $buyer->payer_kind);
        $this->assertSame('invoice', $buyer->document_kind);
        $this->assertNull($invoices->amounts($booking)['agent_portal']);
        $this->assertSame(200000, $invoices->amounts($booking)['portal_venue']);
        $this->assertSame($buyer->id, $invoices->issueBuyer($booking->id, $adminActor)->id);

        $invoices->claim($booking->id, $buyer->id, $clientActor, 'Перевёл СБП');
        $invoices->settle($booking->id, $buyer->id, $adminActor, 'received', 'Поступление проверено');
        $provider = $booking->invoices()->where('kind', 'portal_venue')->firstOrFail();
        $this->assertSame('venue', $provider->issuer_kind);
        $this->assertSame('portal', $provider->payer_kind);
        $this->assertSame('internal_settlement', $provider->document_kind);
        $this->assertDatabaseCount('venue_rental_invoices', 2);

        $invoices->settle($booking->id, $provider->id, $adminActor, 'postpay', 'Расчёт с площадкой в конце недели');
        $this->assertSame('postpay', $provider->fresh()->status);
        $this->assertNull($provider->fresh()->settled_at);
        $this->assertSame(VenueBookingStatusEnum::CONFIRMED, app(ConfirmVenueBookingHandler::class)
            ->handle($booking->id, $adminActor)->status);

        // Postpaid invoice remains a real debt after the booking is confirmed.
        // Repayment must stay possible after both the former hold and planned settlement dates.
        $this->travel(2)->hours();
        $this->assertSame(VenueBookingStatusEnum::CONFIRMED, $booking->fresh()->status);
        $invoices->settle($booking->id, $provider->id, $adminActor, 'received', 'Площадка подтвердила перевод');
        $this->assertSame('paid', $provider->fresh()->status);
        $this->assertDatabaseCount('venue_rental_invoice_payments', 2);
    }

    public function test_with_agent_three_invoices_and_agent_cannot_mark_portal_receipt(): void
    {
        [, $adminActor, $venue, $client, $clientActor] = $this->scenario(true);
        $booking = $this->requested($venue, $client, $clientActor);
        $agentUserId = data_get($booking->quote_snapshot, 'pricing.commissions.agent.user_id');
        $this->assertNotNull($agentUserId);
        $agent = User::query()->findOrFail($agentUserId);
        $agentActor = app(CurrentActorResolver::class)->resolve($agent, null);

        app(VenueExternalHoldManager::class)->confirm(
            $booking->id, $agentActor, CarbonImmutable::now()->addMinutes(25),
            'phone', null, 'Слот согласован с площадкой',
        );
        $invoices = app(VenueRentalInvoiceManager::class);
        $buyer = $invoices->issueBuyer($booking->id, $agentActor);
        $this->assertSame('agent', $buyer->issuer_kind);
        $this->assertSame(230000, $buyer->amount_minor);
        $this->assertSame(220000, $invoices->amounts($booking)['agent_portal']);
        $this->assertSame(200000, $invoices->amounts($booking)['portal_venue']);

        $invoices->settle($booking->id, $buyer->id, $agentActor, 'received', 'Поступило 2300 ₽');
        $middle = $booking->invoices()->where('kind', 'agent_portal')->firstOrFail();
        $this->assertSame('portal', $middle->issuer_kind);
        $this->assertSame('agent', $middle->payer_kind);
        $this->assertSame(220000, $middle->amount_minor);
        $this->assertDatabaseCount('venue_rental_invoices', 2);

        try {
            $invoices->settle($booking->id, $middle->id, $agentActor, 'received', 'Я подтвердил за портал');
            $this->fail('An agent must not attest to MSKBA receipt by default.');
        } catch (VenueBookingTransitionException $exception) {
            $this->assertSame('BOOKING_FORBIDDEN', $exception->errorCode);
        }

        $invoices->settle($booking->id, $middle->id, $adminActor, 'received', 'MSKBA получил перевод');
        $provider = $booking->invoices()->where('kind', 'portal_venue')->firstOrFail();
        $this->assertSame(200000, $provider->amount_minor);
        $this->assertDatabaseCount('venue_rental_invoices', 3);
        try {
            $invoices->settle($booking->id, $provider->id, $agentActor, 'received', 'За площадку');
            $this->fail('An agent must not attest to the venue receipt.');
        } catch (VenueBookingTransitionException $exception) {
            $this->assertSame('BOOKING_FORBIDDEN', $exception->errorCode);
        }

        $invoices->settle($booking->id, $provider->id, $adminActor, 'postpay', 'Согласована постоплата');
        $this->assertTrue($invoices->isReady($booking));
        $this->assertSame(VenueBookingStatusEnum::CONFIRMED,
            app(ConfirmVenueBookingHandler::class)->handle($booking->id, $adminActor)->status);
    }

    public function test_invoice_deadlines_are_distinct_and_late_internal_due_does_not_extend_hold(): void
    {
        [, $adminActor, $venue, $client, $clientActor] = $this->scenario();
        $booking = $this->requested($venue, $client, $clientActor);
        app(VenueExternalHoldManager::class)->confirm(
            $booking->id, $adminActor, CarbonImmutable::now()->addMinutes(12),
            'phone', null, 'Подтверждено на 12 минут',
        );
        $invoices = app(VenueRentalInvoiceManager::class);
        $buyer = $invoices->issueBuyer($booking->id, $adminActor);

        // A buyer's payment deadline is capped by the hold, even if the template/window allows more.
        $this->assertEquals(
            $booking->fresh()->effective_protection_until->getTimestamp(),
            $buyer->due_at->getTimestamp(),
        );

        $invoices->settle($booking->id, $buyer->id, $adminActor, 'received', 'Поступление проверено');
        $provider = $booking->invoices()->where('kind', 'portal_venue')->firstOrFail();

        // The internal settlement deadline can outlive the hold, but cannot extend the booking decision.
        $this->assertGreaterThan(
            $booking->fresh()->effective_protection_until->getTimestamp(),
            $provider->due_at->getTimestamp(),
        );
        $this->travel(13)->minutes();
        $this->assertFalse(app(VenueBookingActionState::class)->for($booking->fresh(), $adminActor)['confirm']['allowed']);

        try {
            app(ConfirmVenueBookingHandler::class)->handle($booking->id, $adminActor);
            $this->fail('Internal settlement due time must not extend the booking hold.');
        } catch (VenueBookingTransitionException $exception) {
            $this->assertSame('HOLD_EXPIRED', $exception->errorCode);
        }
    }

    public function test_invoice_automatic_issuance_requires_eligible_template_and_actual_hold(): void
    {
        [, $adminActor, $venue, $client, $clientActor] = $this->scenario();
        VenueRentalInvoiceTemplate::query()->create([
            'scope' => 'venue', 'venue_id' => $venue->id,
            'name' => 'СБП', 'title' => 'Аренда баскетбольного зала',
            'payment_methods' => ['bank_transfer'],
            'payment_instructions' => 'Оплата по согласованию',
            'due_minutes' => 30, 'auto_send' => true, 'enabled' => true,
            'priority' => 1,
        ]);
        $booking = $this->requested($venue, $client, $clientActor);
        $this->assertDatabaseCount('venue_rental_invoices', 0);

        app(VenueExternalHoldManager::class)->confirm(
            $booking->id, $adminActor, CarbonImmutable::now()->addMinutes(15),
            'phone', null, 'Получено подтверждение',
        );
        $this->assertDatabaseCount('venue_rental_invoices', 1);
        $invoice = $booking->invoices()->firstOrFail();
        $this->assertSame('buyer', $invoice->kind);
        $this->assertSame('Аренда баскетбольного зала', $invoice->title);
        $this->assertEqualsWithDelta(now()->addMinutes(15)->getTimestamp(), $invoice->due_at->getTimestamp(), 1);
    }
}
