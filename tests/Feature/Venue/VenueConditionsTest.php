<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueRevisionManager;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class VenueConditionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_conditions_update_preserves_other_unconfirmed_venue_data(): void
    {
        [$user, $venue] = $this->ownedVenue();
        $original = $venue->only(['name', 'type', 'short_description', 'full_description', 'location_id', 'raw_address']);
        $this->actingAs($user)->put(route('account.venues.conditions.update', $venue->routeIdentifier()), [
            'access_type' => 'paid', 'requires_booking_approval' => '1',
        ])->assertRedirect(route('account.venues.conditions.edit', $venue->routeIdentifier()))->assertSessionHasNoErrors();

        $venue->refresh();
        $this->assertTrue($venue->requires_payment);
        $this->assertTrue($venue->requires_booking_approval);
        $this->assertSame($original, $venue->only(array_keys($original)));
        $this->get(route('account.venues.conditions.edit', $venue->routeIdentifier()))->assertOk();
    }

    public function test_confirmed_conditions_preserve_existing_draft_and_survive_details_save(): void
    {
        [$user, $venue] = $this->ownedVenue(VenueStatusEnum::CONFIRMED);
        $actor = app(CurrentActorResolver::class)->resolve($user, null);
        $revision = DB::transaction(fn () => app(VenueRevisionManager::class)->getOrCreateDraft($venue, $actor));
        $payload = $revision->payload;
        $payload['details']['short_description'] = 'Описание в черновике';
        $payload['tags'] = ['Новый тег'];
        $revision->update(['payload' => $payload]);

        $this->actingAs($user)->put(route('account.venues.conditions.update', $venue->routeIdentifier()), [
            'access_type' => 'paid', 'requires_booking_approval' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $after = $revision->fresh()->payload;
        $expected = $payload;
        $expected['details']['access_type'] = 'paid';
        $expected['details']['requires_booking_approval'] = true;
        $this->assertSame($expected, $after);
        $this->assertFalse($venue->fresh()->requires_payment);

        $this->put(route('account.venues.update', $venue->routeIdentifier()), [
            'name' => $venue->name,
            'type' => $venue->type->value,
            'short_description' => 'Обновленное описание',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $after = $revision->fresh()->payload;
        $this->assertSame('paid', $after['details']['access_type']);
        $this->assertTrue($after['details']['requires_booking_approval']);
        $this->assertSame('Обновленное описание', $after['details']['short_description']);
    }

    public function test_stranger_cannot_view_or_update_conditions_and_pending_moderation_blocks_owner(): void
    {
        [$owner, $venue] = $this->ownedVenue();
        $stranger = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($stranger)->get(route('account.venues.conditions.edit', $venue->routeIdentifier()))->assertForbidden();
        $this->put(route('account.venues.conditions.update', $venue->routeIdentifier()), [
            'access_type' => 'paid', 'requires_booking_approval' => '1',
        ])->assertForbidden();

        $this->actingAs($owner)->post(route('account.venues.moderation.submit', $venue->routeIdentifier()))->assertRedirect();
        $this->from(route('account.venues.conditions.edit', $venue->routeIdentifier()))->put(route('account.venues.conditions.update', $venue->routeIdentifier()), [
            'access_type' => 'paid', 'requires_booking_approval' => '1',
        ])->assertRedirect()->assertSessionHas('error');
        $this->assertFalse($venue->fresh()->requires_payment);
    }

    private function ownedVenue(VenueStatusEnum $status = VenueStatusEnum::UNCONFIRMED): array
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create([
            'created_by_actor_id' => app(CurrentActorResolver::class)->resolve($user, null)->id,
            'status' => $status,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);

        return [$user, $venue];
    }
}
