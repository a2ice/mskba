<?php

namespace Tests\Feature\PlayInterest;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\PlayInterest\Domain\Models\VenueGroupOffer;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class VenueGroupOffersTest extends TestCase
{
    use RefreshDatabase;

    private string $oldTheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldTheme = (string) config('themes.active');
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    protected function tearDown(): void
    {
        config()->set('themes.active', $this->oldTheme);
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/'.$this->oldTheme.'/views'));
        parent::tearDown();
    }

    private function venue(): Venue
    {
        return Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);
    }

    private function manager(): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED->value,
            'system_role' => UserSystemRoleEnum::SUPERADMIN->value,
        ]);
    }

    private function values(string $code = 'AUTUMN2026'): array
    {
        return [
            'title' => 'Предложение на аренду',
            'code' => $code,
            'discount_type' => 'percent',
            'discount_value' => 15,
            'valid_from' => now()->subDay()->format('Y-m-d\TH:i'),
        ];
    }

    private function subscribe(User $user, Venue $venue): void
    {
        PlayIntent::query()->create([
            'user_id' => $user->id,
            'venue_id' => $venue->id,
            'name' => 'Подписка на площадку',
            'status' => 'active',
            'expires_at' => null,
        ]);
    }

    public function test_only_confirmed_commercial_manager_can_issue_and_publish_promo(): void
    {
        $venue = $this->venue();
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED->value]);
        $url = route('venues.interest-group.offers.store', ['venue' => $venue->id]);

        $this->post($url, $this->values())->assertRedirect();
        $this->actingAs($user)->post($url, $this->values())->assertForbidden();

        $admin = $this->manager();
        $this->actingAs($admin)->post($url, $this->values())->assertRedirect();
        $offer = VenueGroupOffer::query()->sole();
        $this->assertSame('draft', $offer->status);
        $this->assertSame('venue:'.$venue->id, $offer->group_key);
        $this->assertSame('AUTUMN2026', $offer->code_ciphertext);
        $this->assertNotSame('AUTUMN2026', $offer->getRawOriginal('code_ciphertext'));
        $this->assertSame(1, $offer->audits()->count());

        $this->post(route('venues.interest-group.offers.transition', [
            'venue' => $venue->id, 'offer' => $offer->id, 'action' => 'publish',
        ]))->assertRedirect();

        $this->assertSame('active', $offer->fresh()->status);
        $this->assertSame(2, $offer->fresh()->terms_version);
        $this->assertSame(2, $offer->audits()->count());
    }

    public function test_only_active_subscribers_see_published_codes_not_guests_or_other_venues(): void
    {
        $venue = $this->venue();
        $other = $this->venue();
        $member = User::factory()->create();
        $stranger = User::factory()->create();
        $this->subscribe($member, $venue);
        $admin = $this->manager();
        $this->actingAs($admin)->post(route('venues.interest-group.offers.store', ['venue'=>$venue->id]), $this->values());
        $offer = VenueGroupOffer::query()->sole();
        $url = route('venues.interest-group', ['venue' => $venue->id]);

        $this->get($url)->assertDontSee('AUTUMN2026');
        $this->actingAs($member)->get($url)->assertDontSee('AUTUMN2026');
        $this->actingAs($admin)->post(route('venues.interest-group.offers.transition', [
            'venue' => $venue->id, 'offer' => $offer->id, 'action' => 'publish',
        ]))->assertRedirect();

        $this->actingAs($member)->get($url)->assertOk()->assertSee('AUTUMN2026');
        $this->actingAs($stranger)->get($url)->assertOk()->assertDontSee('AUTUMN2026');
        $this->get(route('venues.interest-group', ['venue'=>$other->id]))->assertDontSee('AUTUMN2026');

        PlayIntent::query()->where('user_id', $member->id)->update(['status' => 'paused']);
        $this->actingAs($member)->get($url)->assertDontSee('AUTUMN2026');
    }

    public function test_pause_revoke_and_foreign_venue_mutations_are_enforced(): void
    {
        $venue = $this->venue();
        $other = $this->venue();
        $this->actingAs($this->manager())->post(
            route('venues.interest-group.offers.store', ['venue'=>$venue->id]),
            $this->values('WINTER2026'),
        );
        $offer = VenueGroupOffer::query()->sole();

        $this->post(route('venues.interest-group.offers.transition', [
            'venue'=>$other->id, 'offer'=>$offer->id, 'action'=>'publish',
        ]))->assertNotFound();

        $transition = fn (string $action): string => route('venues.interest-group.offers.transition', [
            'venue'=>$venue->id, 'offer'=>$offer->id, 'action'=>$action,
        ]);
        $this->post($transition('publish'))->assertRedirect();
        $this->post($transition('pause'))->assertRedirect();
        $this->assertSame('paused', $offer->fresh()->status);
        $this->post($transition('revoke'))->assertRedirect();
        $this->post($transition('publish'))->assertSessionHasErrors('offer');
        $this->assertSame('revoked', $offer->fresh()->status);
        $this->assertSame(4, $offer->audits()->count());
    }

    public function test_validation_normalization_indefinite_and_duplicate_codes(): void
    {
        $venue = $this->venue();
        $url = route('venues.interest-group.offers.store', ['venue'=>$venue->id]);
        $this->actingAs($this->manager())
            ->post($url, array_replace($this->values('SAVE2026'), ['discount_value'=>95]))
            ->assertSessionHasErrors('discount_value');
        $this->post($url, array_replace($this->values('SAVE2026'), [
            'valid_until'=> now()->subDays(2)->format('Y-m-d\TH:i'),
        ]))->assertSessionHasErrors('valid_until');

        $this->post($url, $this->values('SAVE2026'))->assertRedirect();
        $this->post($url, $this->values('save2026'))->assertSessionHasErrors('code');
        $offer = VenueGroupOffer::query()->sole();
        $this->assertNull($offer->valid_until);
        $this->assertSame(hash('sha256', 'SAVE2026'), $offer->code_hash);
        $this->assertSame(1, $offer->terms_version);
    }
}
