<?php

namespace Tests\Feature\PlayInterest;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Amenity;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlayIntentApiTest extends TestCase
{
    use RefreshDatabase;

    private function payload(Venue $venue): array
    {
        return [
            'venue_id' => $venue->id,
            'cadence' => 'regular',
            'weekdays' => [2, 4],
            'windows' => [
                ['weekday' => 2, 'start_time' => '19:00', 'end_time' => '21:30'],
                ['weekday' => 4, 'start_time' => '22:00', 'end_time' => '02:00', 'end_next_day' => true],
            ],
            'formats' => ['3x3', '5x5'],
            'duration_minutes' => 90,
        ];
    }

    public function test_mskba_app_venue_page_exposes_real_interest_dialog_entry_point(): void
    {
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(\App\Presentation\Theming\ThemeResolver::class);
        \Illuminate\Support\Facades\View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));

        $venue = Venue::factory()->create([
            'name' => 'Площадка для новой темы',
            'status' => VenueStatusEnum::CONFIRMED->value,
        ]);

        $this->get(route('venues.show', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('Площадка для новой темы')
            ->assertSee('Хочу здесь играть')
            ->assertSee('data-mskba-play-intent-root', false);

        $this->actingAs(User::factory()->create())
            ->get(route('play-intents.mine'))
            ->assertOk()
            ->assertSee('data-play-interests', false);
    }

    public function test_owner_can_save_read_update_pause_resume_and_cancel_an_interest(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);

        $this->actingAs($user)->postJson(route('play-intents.store'), $this->payload($venue))
            ->assertCreated()
            ->assertJsonPath('data.cadence', 'regular')
            ->assertJsonPath('data.windows.1.end_next_day', true)
            ->assertJsonPath('data.notify_offers', false)
            ->assertJsonPath('data.effective_status', 'active');
        $id = PlayIntent::query()->sole()->id;

        $this->getJson(route('play-intents.index'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);

        $this->putJson(route('play-intents.update', $id), [
            ...$this->payload($venue),
            'formats' => ['5x5'],
        ])->assertOk()->assertJsonPath('data.formats.0', '5x5');

        $this->patchJson(route('play-intents.pause', $id))
            ->assertOk()->assertJsonPath('data.status', 'paused');
        $this->patchJson(route('play-intents.resume', $id))
            ->assertOk()->assertJsonPath('data.status', 'active');
        $this->deleteJson(route('play-intents.destroy', $id))
            ->assertOk();
        $this->assertDatabaseHas('play_intents', ['id' => $id, 'status' => 'cancelled']);
    }

    public function test_a_different_account_cannot_read_mutate_or_recommend_another_users_interest(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);

        $id = $this->actingAs($owner)
            ->postJson(route('play-intents.store'), $this->payload($venue))
            ->assertCreated()->json('data.id');

        $this->actingAs($other)->getJson(route('play-intents.index'))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->putJson(route('play-intents.update', $id), $this->payload($venue))->assertNotFound();
        $this->patchJson(route('play-intents.pause', $id))->assertNotFound();
        $this->patchJson(route('play-intents.resume', $id))->assertNotFound();
        $this->deleteJson(route('play-intents.destroy', $id))->assertNotFound();
        $this->getJson(route('play-intents.recommendations', $id))->assertNotFound();
    }

    public function test_guest_cannot_save_and_unconfirmed_venue_is_rejected(): void
    {
        $hidden = Venue::factory()->create(['status' => VenueStatusEnum::UNCONFIRMED->value]);
        $this->postJson(route('play-intents.store'), $this->payload($hidden))
            ->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->postJson(route('play-intents.store'), $this->payload($hidden))
            ->assertUnprocessable()->assertJsonValidationErrors('venue_id');
    }

    public function test_criteria_first_must_provide_center_and_radius(): void
    {
        $user = User::factory()->create();
        $payload = ['cadence' => 'flexible', 'include_similar_venues' => true];
        $this->actingAs($user)->postJson(route('play-intents.store'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('center_latitude');

        $this->postJson(route('play-intents.store'), [
            ...$payload,
            'center_latitude' => 55.75,
            'center_longitude' => 37.61,
            'radius_km' => 10,
        ])->assertCreated()->assertJsonPath('data.venue_id', null);
    }

    public function test_validates_scheduled_time_and_the_one_off_search_horizon(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);

        $this->actingAs($user)->postJson(route('play-intents.store'), [
            'venue_id' => $venue->id,
            'cadence' => 'once',
            'period_from' => now()->toDateString(),
            'period_until' => now()->addDays(14)->toDateString(),
            'windows' => [['start_time' => '22:00', 'end_time' => '02:00']],
        ])->assertUnprocessable()->assertJsonValidationErrors('windows.0.end_time');

        $this->postJson(route('play-intents.store'), [
            'venue_id' => $venue->id,
            'cadence' => 'once',
            'period_from' => now()->toDateString(),
            'period_until' => now()->addDays(14)->toDateString(),
        ])->assertCreated()->assertJsonPath('data.cadence', 'once');

        $this->postJson(route('play-intents.store'), [
            'venue_id' => $venue->id,
            'cadence' => 'once',
            'period_from' => now()->toDateString(),
            'period_until' => now()->addDays(31)->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('period_until');
    }

    public function test_active_amenities_can_be_required_or_preferred_and_duplicate_ids_fail(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);
        $amenity = Amenity::factory()->create(['is_active' => true]);
        $data = [
            ...$this->payload($venue),
            'amenity_preferences' => [
                ['amenity_id' => $amenity->id, 'priority' => 'required'],
            ],
        ];

        $this->actingAs($user)->postJson(route('play-intents.store'), $data)
            ->assertCreated()->assertJsonPath('data.amenity_preferences.0.priority', 'required');

        $this->postJson(route('play-intents.store'), [
            ...$data, 'amenity_preferences' => [
                ['amenity_id' => $amenity->id, 'priority' => 'preferred'],
                ['amenity_id' => $amenity->id, 'priority' => 'required'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('amenity_preferences.1.amenity_id');

        $this->getJson(route('play-intents.amenities'))
            ->assertOk()->assertJsonFragment(['id' => $amenity->id]);
    }

    public function test_retry_after_auth_creates_only_one_interest_for_the_same_request_id(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);
        $request = [
            ...$this->payload($venue),
            'client_request_id' => '307d52d3-ecb4-462c-a428-52b5bc592612',
        ];
        $this->actingAs($user)->postJson(route('play-intents.store'), $request)
            ->assertCreated();
        $this->postJson(route('play-intents.store'), $request)
            ->assertCreated();
        $this->assertDatabaseCount('play_intents', 1);
    }

    public function test_exact_slot_search_respects_the_published_schedule(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);
        $schedule = $venue->schedule()->create(['timezone' => 'Europe/Moscow']);
        foreach (range(1, 7) as $day) {
            $schedule->intervals()->create([
                'day_of_week' => $day,
                'starts_at' => '09:00',
                'ends_at' => '22:00',
                'sort_order' => 1,
            ]);
        }

        $id = $this->actingAs($user)
            ->postJson(route('play-intents.store'), $this->payload($venue))
            ->assertCreated()->json('data.id');

        $this->getJson(route('play-intents.slots', [
            'playIntent' => $id,
            'date' => now('Europe/Moscow')->addDays(3)->toDateString(),
            'time' => '19:00',
            'duration_minutes' => 90,
            'tolerance_minutes' => 0,
        ]))->assertOk()
            ->assertJsonPath('data.0.venue_id', $venue->id)
            ->assertJsonPath('data.0.booking_availability', 'available_at_check')
            ->assertJsonPath('meta.reservation_created', false);
    }

    public function test_recommendations_explain_that_availability_is_not_yet_checked(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);

        $id = $this->actingAs($user)
            ->postJson(route('play-intents.store'), $this->payload($venue))
            ->assertCreated()->json('data.id');

        $this->getJson(route('play-intents.recommendations', $id))
            ->assertOk()
            ->assertJsonPath('data.0.venue_id', $venue->id)
            ->assertJsonPath('data.0.booking_availability', 'not_checked')
            ->assertJsonPath('meta.availability_verified', false);
    }
}
