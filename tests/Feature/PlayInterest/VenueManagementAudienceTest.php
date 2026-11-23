<?php

namespace Tests\Feature\PlayInterest;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Favorites\Domain\Models\Favorite;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum as Privacy;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum as Visibility;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\PlayInterest\Application\Services\VenueSubscriptionAudience;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\Services\VenueSpecialTermsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VenueManagementAudienceTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name): User
    {
        return User::factory()->create(['username' => $name, 'status' => UserStatusEnum::CONFIRMED]);
    }

    private function venue(): Venue
    {
        return Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED, 'is_published' => true]);
    }

    private function grant(User $user, Venue $venue, array $permissions): Contract
    {
        $contract = Contract::query()->create([
            'family' => ContractFamilyEnum::MEMBERSHIP, 'status' => ContractStatusEnum::ACTIVE,
            'number' => 'AUD-'.$venue->id.'-'.$user->id, 'starts_at' => now()->subDay(),
            'assigner' => UserParticipationRoleAssignerEnum::OTHER,
        ]);
        $contract->membership()->create([
            'scope_type' => ContractMembershipScopeTypeEnum::VENUE, 'scope_id' => $venue->id,
            'user_id' => $user->id, 'access_level' => VenueMembershipAccessLevelEnum::MANAGER->value,
        ]);
        $contract->permissions()->createMany(array_map(
            fn ($permission) => ['permission' => $permission->value], $permissions,
        ));
        return $contract;
    }

    private function subscribe(User $user, Venue $venue, array $extra = []): PlayIntent
    {
        return PlayIntent::query()->create(array_replace([
            'user_id' => $user->id, 'venue_id' => $venue->id, 'name' => 'Audience test',
            'status' => 'active', 'cadence' => 'flexible', 'show_on_venue' => true,
            'notify_offers' => true, 'offers_delivery_channels' => ['in_app'],
        ], $extra));
    }

    public function test_management_scope_deduplicates_and_hides_private_interest_and_respects_exclusions(): void
    {
        $venue = $this->venue();
        $otherVenue = $this->venue();
        $manager = $this->user('audience-manager');
        $public = $this->user('public-fan-test');
        $private = $this->user('private-fan-test');
        $excluded = $this->user('excluded-fan-test');
        $hidden = $this->user('hidden-profile-test');
        $this->grant($manager, $venue, [VenuePermissionEnum::VIEW, VenuePermissionEnum::VIEW_EVENT_INTERESTS]);
        $this->grant($manager, $otherVenue, [VenuePermissionEnum::VIEW]);
        $this->subscribe($public, $venue);
        $this->subscribe($public, $venue, ['name' => 'Second interest']);
        Favorite::query()->create(['user_id' => $public->id, 'entity_type' => 'venue', 'entity_id' => $venue->id]);
        Favorite::query()->create(['user_id' => $private->id, 'entity_type' => 'venue', 'entity_id' => $venue->id]);
        $this->subscribe($private, $venue, ['show_on_venue' => false]);
        $this->subscribe($excluded, $venue, ['excluded_venue_ids' => [$venue->id]]);
        $this->subscribe($hidden, $venue);
        $hidden->privacySettings()->create(['type' => Privacy::PROFILE, 'visibility' => Visibility::NOBODY]);

        $summary = app(VenueSubscriptionAudience::class)->forManagement($venue, $manager);
        $this->assertSame(3, $summary['total']);
        $this->assertSame(2, $summary['anonymous_count']);
        $this->assertSame([$public->id], array_column($summary['people'], 'user_id'));
        $this->assertFalse($summary['people'][0]['can_message']);

        $this->actingAs($manager)->get(route('account.venue-audience.index', $venue))->assertOk()
            ->assertSee('Интересуются')->assertSee('public-fan-test')
            ->assertDontSee('private-fan-test')->assertDontSee('hidden-profile-test')->assertDontSee('excluded-fan-test');
        $this->get(route('account.venue-audience.index', $otherVenue))->assertForbidden();
        $this->get(route('account.venue-audience.backoffice', $venue))->assertForbidden();
        $this->post(route('account.venue-audience.message', [$venue, $public]), ['body' => 'Test'])->assertForbidden();
        $this->actingAs($private)->get(route('account.venue-audience.index', $venue))->assertForbidden();
    }

    public function test_offer_is_scoped_consent_checked_and_idempotent(): void
    {
        $venue = $this->venue();
        $manager = $this->user('offer-manager');
        $recipient = $this->user('offer-recipient');
        $this->grant($manager, $venue, [
            VenuePermissionEnum::VIEW, VenuePermissionEnum::VIEW_EVENT_INTERESTS, VenuePermissionEnum::MANAGE_SPECIAL_TERMS,
        ]);
        $intent = $this->subscribe($recipient, $venue);
        $terms = app(VenueSpecialTermsManager::class);
        $offer = $terms->create($venue, $manager, [
            'title' => 'Персональная скидка', 'description' => 'Тестовое предложение',
            'audience' => 'USER_BOUND', 'recipient_ids' => [$recipient->id], 'auto_apply' => true,
            'discount_type' => 'percent', 'discount_value' => 10,
        ]);
        $terms->transition($venue, $manager, $offer, 'publish');
        $route = route('account.venue-audience.offer', [$venue, $recipient]);
        $this->actingAs($manager);
        $intent->update(['notify_offers' => false]);
        $this->post($route, ['offer_id' => $offer->id])->assertForbidden();
        $intent->update(['notify_offers' => true]);
        $this->post($route, ['offer_id' => $offer->id])->assertRedirect();
        $this->post($route, ['offer_id' => $offer->id])->assertRedirect();
        $this->assertSame(1, UserNotification::query()->where('user_id', $recipient->id)
            ->where('payload->source', 'venue.audience.offer')->where('payload->offer_id', $offer->id)->count());
        $this->assertSame(1, $offer->audits()->where('action', 'notify')->count());
    }
}
