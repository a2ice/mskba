<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\Venues\Support\VenueRentalPolicyEditor;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VenueRentalHoldPolicyParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_filament_preserves_hold_and_financial_rules_and_verification_on_unrelated_edit(): void
    {
        config()->set('features.venue_rental.rental_flow', true);
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00', 'Europe/Moscow'));

        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $this->actingAs($admin);
        $venue = Venue::factory()->create();
        $editor = app(VenueRentalPolicyEditor::class);

        $fields = $editor->fields($venue);
        $this->assertArrayHasKey('rental_policy_external_hold_required', $fields);
        $this->assertArrayHasKey('rental_policy_portal_venue_postpay_allowed', $fields);
        $this->assertArrayHasKey('rental_policy_hold_verification_note', $fields);

        $fields['rental_policy_enabled'] = true;
        $fields['rental_policy_requires_payment'] = true;
        $fields['rental_policy_whole_price_rub'] = '600';
        $fields['rental_policy_hold_duration_minutes'] = 60;
        $fields['rental_policy_external_hold_required'] = true;
        $fields['rental_policy_hold_verification_method'] = 'provider';
        $fields['rental_policy_hold_verification_note'] = 'Basketpoint — подтверждено агрегатором';
        $fields['rental_policy_buyer_postpay_allowed'] = false;
        $fields['rental_policy_agent_portal_postpay_allowed'] = false;
        $fields['rental_policy_portal_venue_postpay_allowed'] = true;
        $fields['rental_policy_agent_portal_self_attestation_allowed'] = false;

        $editor->save($venue, $admin, $fields);
        $first = VenueBookingPolicy::query()->where('venue_id', $venue->id)->where('active_marker', true)->firstOrFail();

        $this->assertSame(1, $first->version);
        $this->assertSame(60, $first->hold_duration_minutes);
        $this->assertTrue($first->external_hold_required);
        $this->assertSame('provider', $first->hold_verification_method);
        $this->assertTrue($first->portal_venue_postpay_allowed);
        $this->assertFalse($first->buyer_postpay_allowed);
        $this->assertNotNull($first->hold_verified_at);
        $this->assertSame($admin->id, $first->hold_verified_by_user_id);

        $reloaded = $editor->fields($venue->fresh());
        $this->assertSame(60, $reloaded['rental_policy_hold_duration_minutes']);
        $this->assertTrue($reloaded['rental_policy_external_hold_required']);
        $this->assertTrue($reloaded['rental_policy_portal_venue_postpay_allowed']);
        $this->assertSame('provider', $reloaded['rental_policy_hold_verification_method']);

        // Saving without changes must not invent a fresh policy version.
        $editor->save($venue->fresh(), $admin, $reloaded);
        $this->assertSame(1, VenueBookingPolicy::query()->where('venue_id', $venue->id)->max('version'));

        $this->travel(10)->minutes();
        $reloaded['rental_policy_whole_price_rub'] = '650';
        $editor->save($venue->fresh(), $admin, $reloaded);
        $second = VenueBookingPolicy::query()->where('venue_id', $venue->id)->where('active_marker', true)->firstOrFail();
        $this->assertSame(2, $second->version);
        $this->assertSame($first->hold_verified_at->getTimestamp(), $second->hold_verified_at->getTimestamp());
        $this->assertSame($first->hold_verified_by_user_id, $second->hold_verified_by_user_id);

        // The verification timestamp changes only when the hold data is actually revisited.
        $this->travel(10)->minutes();
        $verifiedState = $editor->fields($venue->fresh());
        $verifiedState['rental_policy_hold_duration_minutes'] = 45;
        $verifiedState['rental_policy_hold_verification_note'] = 'Повторное подтверждение у Basketpoint';
        $editor->save($venue->fresh(), $admin, $verifiedState);
        $third = VenueBookingPolicy::query()->where('venue_id', $venue->id)->where('active_marker', true)->firstOrFail();
        $this->assertSame(3, $third->version);
        $this->assertSame(45, $third->hold_duration_minutes);
        $this->assertGreaterThan($second->hold_verified_at->getTimestamp(), $third->hold_verified_at->getTimestamp());
        $this->assertTrue($third->portal_venue_postpay_allowed);

        $this->travelBack();
    }
}
