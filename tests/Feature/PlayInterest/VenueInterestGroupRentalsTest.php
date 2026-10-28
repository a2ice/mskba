<?php

namespace Tests\Feature\PlayInterest;

use App\Modules\Coordination\Domain\Enums\VenueRentalCoordinationStatus;
use App\Modules\Coordination\Domain\Models\VenueRentalCoordination;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VenueInterestGroupRentalsTest extends TestCase
{
    use RefreshDatabase;

    private string $previousTheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTheme = (string) config('themes.active');
        config()->set('themes.active', 'mskba_app');
        config()->set('features.venue_rental.rental_flow', true);
        config()->set('features.venue_rental.coordination', true);
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    protected function tearDown(): void
    {
        config()->set('themes.active', $this->previousTheme);
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/'.$this->previousTheme.'/views'));
        parent::tearDown();
    }

    private function gathering(Venue $venue, User $owner, string $title, string $visibility = 'public'): VenueRentalCoordination
    {
        $actor = Actor::factory()->create(['user_id' => $owner->id]);
        return VenueRentalCoordination::query()->create([
            'public_id' => (string) Str::uuid(),
            'venue_id' => $venue->id,
            'organizer_actor_id' => $actor->id,
            'organizer_user_id' => $owner->id,
            'title' => $title,
            'scope' => VenueBookingScopeEnum::WHOLE,
            'status' => VenueRentalCoordinationStatus::OPEN,
            'visibility' => $visibility,
            'participants_visibility' => 'participants',
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addMinutes(90),
        ]);
    }

    public function test_public_gatherings_are_scoped_to_the_venue_and_do_not_claim_a_booking(): void
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $anotherVenue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $owner = User::factory()->create();
        $this->gathering($venue, $owner, 'Открытый сбор');
        $this->gathering($anotherVenue, $owner, 'Другая площадка');

        $this->get(route('venues.interest-group', ['venue' => $venue->id]))
            ->assertOk()->assertSee('Открытый сбор')->assertSee('Слот не забронирован')
            ->assertDontSee('Другая площадка')
            ->assertDontSee('Проверить условия и создать сбор');
    }

    public function test_private_gatherings_are_only_listed_to_organizer_and_active_participants(): void
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $owner = User::factory()->create();
        $participant = User::factory()->create();
        $stranger = User::factory()->create();
        $secret = $this->gathering($venue, $owner, 'Приватный сбор 298', 'private');

        $url = route('venues.interest-group', ['venue' => $venue->id]);
        $this->get($url)->assertOk()->assertDontSee('Приватный сбор 298');
        $this->actingAs($stranger)->get($url)->assertOk()->assertDontSee('Приватный сбор 298');
        $this->actingAs($owner)->get($url)->assertOk()->assertSee('Приватный сбор 298');

        $secret->participants()->create(['user_id' => $participant->id, 'joined_at' => now()]);
        $this->actingAs($participant)->get($url)->assertOk()->assertSee('Приватный сбор 298');
        $secret->participants()->where('user_id', $participant->id)->update(['left_at' => now()]);
        $this->get($url)->assertOk()->assertDontSee('Приватный сбор 298');
    }

    public function test_disabled_feature_hides_all_rental_gatherings_and_creation(): void
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $this->gathering($venue, User::factory()->create(), 'Скрытый из-за флага');
        config()->set('features.venue_rental.coordination', false);

        $this->get(route('venues.interest-group', ['venue' => $venue->id]))
            ->assertOk()->assertSee('Сервис совместной аренды')
            ->assertDontSee('Скрытый из-за флага')
            ->assertDontSee('Проверить условия и создать сбор');
    }

    public function test_app_theme_uses_existing_rental_lifecycle_screen_with_no_implicit_booking(): void
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $owner = User::factory()->create();
        $gathering = $this->gathering($venue, $owner, 'Мой сбор');

        $this->actingAs($owner)->get(route('venue-rental-coordinations.show', $gathering))
            ->assertOk()->assertSee('Слот не забронирован')
            ->assertSee('Подать заявку на аренду')
            ->assertSee('Мой сбор');
        $this->assertDatabaseCount('venue_bookings', 0);
    }
}
