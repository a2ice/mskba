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
            'show_on_venue' => true,
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
            ->assertSee('Хочешь здесь играть?')
            ->assertSee('Создать мероприятие')
            ->assertSee('data-venue-create-event-link', false)
            ->assertSee(route('events.wizard', ['venue_id' => $venue->id]), false)
            ->assertDontSee('data-venue-create-event-dialog', false)
            ->assertDontSee('В разработке')
            ->assertSee('>Расписание</a>', false)
            ->assertSee('data-mskba-play-intent-root', false);

        $this->actingAs(User::factory()->create())
            ->get(route('play-intents.mine'))
            ->assertOk()
            ->assertSee('data-play-interests', false);
    }

    public function test_venue_interest_public_listing_requires_explicit_opt_in(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);
        $this->actingAs($user)->postJson(route('play-intents.store'), [
            'show_on_venue' => true,
            ...$this->payload($venue),
            'show_on_venue' => true,
        ])->assertCreated()->assertJsonPath('data.show_on_venue', true);
        $interest = PlayIntent::query()->sole();
        $this->assertTrue($interest->show_on_venue);

        $this->putJson(route('play-intents.update', $interest), [
            ...$this->payload($venue),
            'venue_id' => null,
            'include_similar_venues' => false,
            'show_on_venue' => true,
        ])->assertOk()
            ->assertJsonPath('data.venue_id', null)
            ->assertJsonPath('data.show_on_venue', true);
        $this->assertTrue($interest->refresh()->show_on_venue);
    }

    public function test_new_subscription_can_be_hidden_without_losing_its_count(): void
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);
        $user = User::factory()->create(['username' => 'hide-this-profile']);

        $this->actingAs($user)
            ->postJson(route('play-intents.store'), [
                'venue_id' => $venue->id, 'cadence' => 'flexible',
                'weekdays' => [], 'windows' => [], 'show_on_venue' => false,
            ])->assertCreated()->assertJsonPath('data.show_on_venue', false);
        $this->assertDatabaseCount('play_intents', 1);

        $audience = app(\App\Modules\PlayInterest\Application\Services\VenueSubscriptionAudience::class)
            ->forVenue($venue);
        $this->assertSame(1, $audience['total']);
        $this->assertSame(1, $audience['direct_count']);
        $this->assertSame(0, $audience['matched_count']);
        $this->assertSame('hide-this-profile', $audience['direct'][0]['name']);
        $this->assertNull($audience['direct'][0]['avatar']);
        $this->assertSame([], $audience['direct'][0]['positions']);
        $this->assertNull($audience['direct'][0]['experience_years']);
        $this->assertFalse($audience['direct'][0]['public']);

        $record = PlayIntent::query()->sole();
        $this->putJson(route('play-intents.update', $record), [
            ...$this->payload($venue), 'show_on_venue' => true,
        ])->assertOk()->assertJsonPath('data.show_on_venue', true);
        $visible = app(\App\Modules\PlayInterest\Application\Services\VenueSubscriptionAudience::class)
            ->forVenue($venue);
        $this->assertSame('hide-this-profile', $visible['direct'][0]['name']);
    }

    public function test_venue_specific_interest_round_trip_exposes_the_selected_venue_to_its_owner(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create([
            'name' => 'Школа №1794',
            'status' => VenueStatusEnum::CONFIRMED->value,
        ]);

        $this->actingAs($user)->postJson(route('play-intents.store'), [
            'show_on_venue' => true,
            ...$this->payload($venue),
            'name' => 'На ШК',
            'cadence' => 'flexible',
            'weekdays' => [],
            'windows' => [],
        ])->assertCreated()
            ->assertJsonPath('data.venue_id', $venue->id)
            ->assertJsonPath('data.venue.id', $venue->id)
            ->assertJsonPath('data.venue.name', 'Школа №1794')
            ->assertJsonPath('data.venue.url', route('venues.show', $venue->routeIdentifier()));

        $record = PlayIntent::query()->sole();
        $this->assertSame($venue->id, $record->venue_id);
        $this->getJson(route('play-intents.index'))->assertOk()
            ->assertJsonPath('data.0.name', 'На ШК')
            ->assertJsonPath('data.0.venue_id', $venue->id)
            ->assertJsonPath('data.0.venue.name', 'Школа №1794')
            ->assertJsonPath('data.0.venue.url', route('venues.show', $venue->routeIdentifier()));

        // Opening the edit dialog from the account uses this returned
        // venue object; saving unchanged criteria must keep the same link.
        $this->putJson(route('play-intents.update', $record), [
            ...$this->payload($venue),
            'name' => 'На ШК обновлённый',
        ])->assertOk()
            ->assertJsonPath('data.venue_id', $venue->id)
            ->assertJsonPath('data.venue.name', 'Школа №1794');
        $this->assertSame($venue->id, $record->refresh()->venue_id);
    }

    public function test_venue_page_shows_login_but_hides_avatar_and_game_stats_for_private_subscription(): void
    {
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(\App\Presentation\Theming\ThemeResolver::class);
        \Illuminate\Support\Facades\View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));

        $venue = Venue::factory()->create([
            'name' => 'Школа №1794 — замечательная баскетбольная площадка',
            'status' => VenueStatusEnum::CONFIRMED->value,
        ]);
        $user = User::factory()->create(['username' => 'private-interest-test']);
        $other = User::factory()->create(['username' => 'private-interest-other']);

        $this->actingAs($user)->postJson(route('play-intents.store'), [
            'show_on_venue' => true,
            ...$this->payload($venue), 'cadence' => 'flexible', 'weekdays' => [], 'windows' => [],
        ])->assertCreated();
        $this->actingAs($other)->postJson(route('play-intents.store'), [
            'show_on_venue' => true,
            ...$this->payload($venue), 'cadence' => 'flexible', 'weekdays' => [], 'windows' => [],
        ])->assertCreated();
        // A hidden subscription still exposes the login, not the avatar or game profile.
        PlayIntent::query()->where('user_id', $other->id)->update(['show_on_venue' => false]);

        $expectedBreadcrumb = \Illuminate\Support\Str::substr($venue->name, 0, 25).'...';
        $this->actingAs($user)->get(route('venues.show', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee($expectedBreadcrumb)
            ->assertSee('Площадкой интересуются')
            ->assertSee('data-play-venue-interest-summary', false)
            ->assertSee('2 игрока')
            ->assertSee('class="app-venue-interests-dialog__total-number">2</strong>', false)
            ->assertSee('class="app-venue-interests-dialog__total-word">игрока</span>', false)
            ->assertSee('class="app-venue-interest-summary__profile-icon"', false)
            ->assertDontSee('Подходят по условиям')
            ->assertDontSee('напрямую ·')
            ->assertDontSee('Без данных профиля')
            ->assertSee('href="'.route('play-intents.mine').'"', false)
            ->assertSee('Вы подписаны')
            ->assertDontSee('Отображается только число интересов')
            ->assertSee('private-interest-other')
            ->assertSee('private-interest-test');

        // Public viewers see usernames in both cases, never hidden game details.
        auth()->logout();
        $this->get(route('venues.show', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('Площадкой интересуются')
            ->assertSee('private-interest-test')
            ->assertSee('private-interest-other')
            ->assertDontSee('Скрытый профиль')
            ->assertDontSee('Без данных профиля');
    }

    public function test_subscribed_viewer_sees_own_hidden_game_profile_but_others_do_not(): void
    {
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(\App\Presentation\Theming\ThemeResolver::class);
        \Illuminate\Support\Facades\View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));

        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);
        $hidden = User::factory()->create(['username' => 'hidden-self-player']);
        $visible = User::factory()->create(['username' => 'open-player']);

        $hiddenProfile = \App\Modules\Identity\Domain\Models\Participation\PlayerProfile::factory()
            ->for($hidden)->create(['height_cm' => 193, 'experience_started_year' => 2018]);
        $avatarProfile = $hidden->profile()->firstOrCreate([]);
        $avatarProfile->media()->save(
            \App\Modules\Media\Domain\Models\Media::factory()->make([
                'collection' => 'avatar', 'disk' => 'public',
                'path' => 'avatars/hidden-self-unique.jpg', 'is_featured' => true,
            ])
        );
        $visibleProfile = \App\Modules\Identity\Domain\Models\Participation\PlayerProfile::factory()
            ->for($visible)->create(['height_cm' => 178, 'experience_started_year' => 2019]);

        $this->actingAs($hidden)->postJson(route('play-intents.store'), [
            ...$this->payload($venue), 'cadence' => 'flexible', 'windows' => [],
            'show_on_venue' => false,
        ])->assertCreated();
        $this->actingAs($visible)->postJson(route('play-intents.store'), [
            ...$this->payload($venue), 'cadence' => 'flexible', 'windows' => [],
            'show_on_venue' => true,
        ])->assertCreated();

        $this->actingAs($hidden);
        $own = app(\App\Modules\PlayInterest\Application\Services\VenueSubscriptionAudience::class)
            ->forVenue($venue);
        $ownRow = collect($own['direct'])->firstWhere('name', 'hidden-self-player');
        $this->assertTrue($ownRow['is_self']);
        $this->assertFalse($ownRow['public']);
        $this->assertTrue($ownRow['can_view_details']);
        $this->assertSame(193, $ownRow['height_cm']);
        $this->assertSame('/storage/avatars/hidden-self-unique.jpg', $ownRow['avatar']);

        $ownHtml = $this->get(route('venues.show', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('Это вы')
            ->assertSee('193 см')
            ->assertSee('178 см')
            ->assertSee('data-venue-player-details-dialog', false)
            ->assertSee('data-venue-player-details-open', false)
            ->getContent();
        $this->assertGreaterThanOrEqual(2, substr_count($ownHtml, 'src="/storage/avatars/hidden-self-unique.jpg"'));
        $this->assertSame(3, substr_count($ownHtml, 'Вы подписаны'));
        $this->assertStringNotContainsString('Отменить подписку', $ownHtml);

        auth()->logout();
        $guestAudience = app(\App\Modules\PlayInterest\Application\Services\VenueSubscriptionAudience::class)
            ->forVenue($venue);
        $hiddenRow = collect($guestAudience['direct'])->firstWhere('name', 'hidden-self-player');
        $this->assertFalse($hiddenRow['is_self']);
        $this->assertFalse($hiddenRow['can_view_details']);
        $this->assertNull($hiddenRow['height_cm']);
        $this->assertNull($hiddenRow['avatar']);
        $this->assertSame([], $hiddenRow['skills']);
        $this->get(route('venues.show', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('hidden-self-player')
            ->assertDontSee('Это вы')
            ->assertDontSee('hidden-self-unique.jpg')
            ->assertDontSee('193 см')
            ->assertSee('178 см')
            ->assertSee('data-venue-player-details-open', false)
            ->assertDontSee('Вы подписаны');
    }

    public function test_free_venue_schedule_displays_published_hours_not_booking_warnings(): void
    {
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(\App\Presentation\Theming\ThemeResolver::class);
        \Illuminate\Support\Facades\View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-24 10:00:00', 'Europe/Moscow'));

        $venue = Venue::factory()->create([
            'name' => 'Бесплатная уличная площадка',
            'status' => VenueStatusEnum::CONFIRMED->value,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        $schedule = \App\Modules\Venue\Domain\Models\VenueSchedule::factory()
            ->for($venue)->create(['timezone' => 'Europe/Moscow']);
        \App\Modules\Venue\Domain\Models\VenueScheduleInterval::factory()
            ->for($schedule, 'schedule')->create([
                'day_of_week' => 6,
                'starts_at' => '10:00', 'ends_at' => '22:00',
            ]);

        $this->get(route('venues.show', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('aria-label="Часы работы"', false)
            ->assertSee('href="#clock"', false)
            ->assertDontSee('Расписание:')
            ->assertSee('10:00–22:00')
            ->assertDontSee('Нет опубликованных занятых слотов')
            ->assertDontSee('Наличие свободного времени и стоимость уточняются')
            ->assertDontSee('Подтверждённых данных о свободных интервалах');
        $this->travelBack();
    }

    public function test_overnight_event_is_shown_as_a_continuation_not_as_a_new_start_next_day(): void
    {
        config()->set('app.timezone', 'Europe/Moscow');
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(\App\Presentation\Theming\ThemeResolver::class);
        \Illuminate\Support\Facades\View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-24 10:00:00', 'Europe/Moscow'));
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED->value,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        // The real venue has a Moscow schedule; without it this handler
        // intentionally uses the application's default timezone.
        \App\Modules\Venue\Domain\Models\VenueSchedule::factory()
            ->for($venue)->create(['timezone' => 'Europe/Moscow']);
        $from = \Carbon\CarbonImmutable::parse('2026-10-24 22:00:00', 'Europe/Moscow');
        $until = \Carbon\CarbonImmutable::parse('2026-10-25 01:00:00', 'Europe/Moscow');
        $event = \App\Modules\Event\Domain\Models\Event::factory()->create([
            'venue_id' => $venue->id,
            'starts_at' => $from,
            'ends_at' => $until,
        ]);
        $venue->bookings()->create([
            'event_id' => $event->id,
            'created_by_actor_id' => $event->organizer_actor_id,
            'status' => \App\Modules\Event\Domain\Enums\VenueBookingStatusEnum::CONFIRMED->value,
            'scope' => \App\Modules\Event\Domain\Enums\VenueBookingScopeEnum::WHOLE->value,
            'starts_at' => $from,
            'ends_at' => $until,
        ]);

        $occupied = app(\App\Modules\Venue\Application\UseCases\ShowVenueHandler::class)
            ->handle($venue->routeIdentifier(), null)->occupancyDays;
        $this->assertSame('22:00–01:00 (+1 день)', $occupied[0]['slots'][0]['timeLabel'] ?? '(missing first-day slot)');
        $this->assertSame('00:00–01:00 (продолжение)', $occupied[1]['slots'][0]['timeLabel'] ?? '(missing next-day slot)');
        $this->get(route('venues.show', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('<h2>Расписание</h2>', false)
            ->assertSee('<h2>Игры и мероприятия</h2>', false)
            ->assertDontSee('app-venue-schedule__slots', false)
            ->assertSee('22:00–01:00 (+1 день)')
            ->assertSee('00:00–01:00 (продолжение)')
            ->assertSee('data-venue-slot-open', false)
            ->assertSee('data-slot-time="22:00–01:00 (+1 день)"', false)
            ->assertSee('data-event-url="'.route('events.show', $event->routeIdentifier()).'"', false)
            ->assertSee('data-venue-slot-dialog', false)
            ->assertDontSee('Нет опубликованных занятых слотов');
        $this->travelBack();
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

    public function test_criteria_only_search_can_detach_a_venue_and_discover_venues_by_preferences(): void
    {
        $user = User::factory()->create();
        $outdoor = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED->value,
            'operational_status' => \App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum::ACTIVE->value,
            'type' => \App\Modules\Venue\Domain\Enums\VenueTypeEnum::STREET_COURT->value,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        $indoor = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED->value,
            'operational_status' => \App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum::ACTIVE->value,
            'type' => \App\Modules\Venue\Domain\Enums\VenueTypeEnum::SPORTS_HALL->value,
        ]);
        $id = $this->actingAs($user)->postJson(route('play-intents.store'), $this->payload($indoor))
            ->assertCreated()->json('data.id');

        // Unlink is an update to the SAME intent, not deletion or creation.
        $this->putJson(route('play-intents.update', $id), [
            'venue_id' => null, 'include_similar_venues' => false,
            'cadence' => 'regular', 'weekdays' => [6],
            'windows' => [['weekday' => 6, 'start_time' => '18:00', 'end_time' => '20:00']],
            'formats' => ['3x3'], 'access_mode' => 'free', 'venue_kind' => 'outdoor',
        ])->assertOk()->assertJsonPath('data.venue_id', null)
            ->assertJsonPath('data.venue', null)
            ->assertJsonPath('data.venue_kind', 'outdoor')
            ->assertJsonPath('data.include_similar_venues', false);

        $record = PlayIntent::query()->findOrFail($id);
        $this->assertNull($record->venue_id);
        $this->assertNull($record->center_latitude);
        $this->assertNull($record->radius_km);
        $this->assertDatabaseCount('play_intents', 1);

        $recommended = $this->getJson(route('play-intents.recommendations', $id))
            ->assertOk()->json('data');
        $this->assertContains($outdoor->id, array_column($recommended, 'venue_id'));
        $this->assertNotContains($indoor->id, array_column($recommended, 'venue_id'));
        $this->getJson(route('play-intents.index'))->assertOk()
            ->assertJsonPath('data.0.venue_id', null)
            ->assertJsonPath('data.0.venue', null);

        // "Similar" is available only for a chosen anchor venue.
        $this->putJson(route('play-intents.update', $id), [
            'venue_id' => null, 'include_similar_venues' => true, 'cadence' => 'flexible',
        ])->assertUnprocessable()->assertJsonValidationErrors('include_similar_venues');
    }

    public function test_validates_scheduled_time_and_the_one_off_search_horizon(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);

        $this->actingAs($user)->postJson(route('play-intents.store'), [
            'show_on_venue' => true,
            'venue_id' => $venue->id,
            'cadence' => 'once',
            'period_from' => now()->toDateString(),
            'period_until' => now()->addDays(14)->toDateString(),
            'windows' => [['start_time' => '22:00', 'end_time' => '02:00']],
        ])->assertUnprocessable()->assertJsonValidationErrors('windows.0.end_time');

        $this->postJson(route('play-intents.store'), [
            'show_on_venue' => true,
            'venue_id' => $venue->id,
            'cadence' => 'once',
            'period_from' => now()->toDateString(),
            'period_until' => now()->addDays(14)->toDateString(),
        ])->assertCreated()->assertJsonPath('data.cadence', 'once');

        $this->postJson(route('play-intents.store'), [
            'show_on_venue' => true,
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
            'show_on_venue' => true,
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

    public function test_venue_kind_only_filters_similar_venues_and_keeps_the_selected_venue(): void
    {
        $user = User::factory()->create();
        $indoor = Venue::factory()->create([
            'type' => \App\Modules\Venue\Domain\Enums\VenueTypeEnum::SPORTS_HALL->value,
            'status' => VenueStatusEnum::CONFIRMED->value,
        ]);
        $outdoor = Venue::factory()->create([
            'type' => \App\Modules\Venue\Domain\Enums\VenueTypeEnum::STREET_COURT->value,
            'status' => VenueStatusEnum::CONFIRMED->value,
        ]);

        // Both venues are colocated to exercise the alternative type filter,
        // rather than accidentally excluding one on geographic distance.
        $origin = $indoor->location->address;
        $outdoor->location->address->update([
            'latitude' => $origin->latitude,
            'longitude' => $origin->longitude,
        ]);

        // A venue-specific interest must ignore a kind filter when the user
        // has not enabled "Подбирать похожие площадки" (including legacy input).
        $this->actingAs($user)->postJson(route('play-intents.store'), [
            'show_on_venue' => true,
            ...$this->payload($indoor),
            'venue_kind' => 'outdoor',
        ])->assertCreated()->assertJsonPath('data.venue_kind', 'any');

        $id = PlayIntent::query()->sole()->id;
        $this->getJson(route('play-intents.recommendations', $id))
            ->assertOk()->assertJsonPath('data.0.venue_id', $indoor->id);

        // Once similar venues are enabled, outdoor is a filter on alternatives.
        // The specifically chosen indoor venue remains present.
        $similarPayload = [
            ...$this->payload($indoor),
            'include_similar_venues' => true,
            'center_latitude' => $origin->latitude,
            'center_longitude' => $origin->longitude,
            'radius_km' => 10,
        ];
        $this->putJson(route('play-intents.update', $id), [
            ...$similarPayload, 'venue_kind' => 'outdoor',
        ])->assertOk()->assertJsonPath('data.venue_kind', 'outdoor');
        $foundIds = collect($this->getJson(route('play-intents.recommendations', $id))
            ->assertOk()->json('data'))->pluck('venue_id')->all();
        $this->assertContains($indoor->id, $foundIds);
        $this->assertContains($outdoor->id, $foundIds);

        $this->putJson(route('play-intents.update', $id), [
            ...$similarPayload, 'venue_kind' => 'indoor',
        ])->assertOk()->assertJsonPath('data.venue_kind', 'indoor');
        $foundIds = collect($this->getJson(route('play-intents.recommendations', $id))
            ->assertOk()->json('data'))->pluck('venue_id')->all();
        $this->assertContains($indoor->id, $foundIds);
        $this->assertNotContains($outdoor->id, $foundIds);

        $this->putJson(route('play-intents.update', $id), [
            ...$similarPayload, 'venue_kind' => 'invalid',
        ])->assertUnprocessable()->assertJsonValidationErrors('venue_kind');
        $this->assertDatabaseCount('play_intents', 1);
    }

    public function test_editing_paused_interest_preserves_its_paused_status(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);
        $id = $this->actingAs($user)->postJson(route('play-intents.store'), $this->payload($venue))
            ->assertCreated()->json('data.id');
        $this->patchJson(route('play-intents.pause', $id))->assertOk();

        $this->putJson(route('play-intents.update', $id), [
            ...$this->payload($venue),
            'venue_kind' => 'outdoor',
            'formats' => ['3x3'],
        ])->assertOk()->assertJsonPath('data.status', 'paused')
            ->assertJsonPath('data.venue_kind', 'any');
        $this->assertDatabaseCount('play_intents', 1);
    }

    public function test_account_interest_navigation_uses_account_breadcrumbs(): void
    {
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(\App\Presentation\Theming\ThemeResolver::class);
        \Illuminate\Support\Facades\View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));

        $this->actingAs(User::factory()->create())
            ->get(route('play-intents.mine'))
            ->assertOk()
            ->assertSee('Мои подписки')
            ->assertSee('data-mskba-play-intent-root', false)
            ->assertDontSee('>Index<', false);
    }

    public function test_optional_interest_name_can_be_created_renamed_and_cleared_without_creating_a_duplicate(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);

        $this->actingAs($user)->postJson(route('play-intents.store'), [
            'show_on_venue' => true,
            ...$this->payload($venue),
            'name' => '  Игры зимой  ',
        ])->assertCreated()->assertJsonPath('data.name', 'Игры зимой');

        $id = PlayIntent::query()->sole()->id;
        $this->getJson(route('play-intents.index'))
            ->assertOk()->assertJsonPath('data.0.name', 'Игры зимой');

        $this->putJson(route('play-intents.update', $id), [
            ...$this->payload($venue),
            'name' => 'Стритбол по выходным',
        ])->assertOk()->assertJsonPath('data.name', 'Стритбол по выходным');
        $this->assertDatabaseCount('play_intents', 1);

        $this->putJson(route('play-intents.update', $id), [
            ...$this->payload($venue),
            'name' => '     ',
        ])->assertOk()->assertJsonPath('data.name', null);
        $this->assertDatabaseHas('play_intents', ['id' => $id, 'name' => null]);
        $this->assertDatabaseCount('play_intents', 1);
    }

    public function test_interest_name_is_optional_and_limited_to_eighty_characters(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);

        $this->actingAs($user)->postJson(route('play-intents.store'), $this->payload($venue))
            ->assertCreated()->assertJsonPath('data.name', null);

        $this->postJson(route('play-intents.store'), [
            'show_on_venue' => true,
            ...$this->payload($venue), 'name' => str_repeat('И', 81),
        ])->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('play_intents', 1);
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
    public function test_direct_and_criteria_matched_users_are_deduplicated_and_exclusions_apply(): void
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);
        $origin = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);
        $address = $venue->location->address;
        $origin->location->address->update([
            'latitude' => $address->latitude, 'longitude' => $address->longitude,
        ]);
        $direct = User::factory()->create(['username' => 'direct-venue-user']);
        $matched = User::factory()->create(['username' => 'conditions-venue-user']);
        $excluded = User::factory()->create(['username' => 'excluded-venue-user']);

        $this->actingAs($direct)->postJson(route('play-intents.store'), [
            ...$this->payload($venue), 'cadence' => 'flexible', 'windows' => [],
        ])->assertCreated();
        // The same canonical user with a second criteria subscription is still direct.
        $this->actingAs($direct)->postJson(route('play-intents.store'), [
            'venue_id' => null, 'cadence' => 'flexible',
            'show_on_venue' => true, 'windows' => [],
        ])->assertCreated();

        $this->actingAs($matched)->postJson(route('play-intents.store'), [
            'venue_id' => null, 'cadence' => 'flexible',
            'show_on_venue' => false, 'windows' => [],
        ])->assertCreated();

        $this->actingAs($excluded)->postJson(route('play-intents.store'), [
            ...$this->payload($origin), 'cadence' => 'flexible', 'windows' => [],
            'include_similar_venues' => true,
            'center_latitude' => $address->latitude,
            'center_longitude' => $address->longitude,
            'radius_km' => 10,
            'excluded_venue_ids' => [$venue->id],
        ])->assertCreated()->assertJsonPath('data.excluded_venue_ids.0', $venue->id);

        $audience = app(\App\Modules\PlayInterest\Application\Services\VenueSubscriptionAudience::class)
            ->forVenue($venue);
        $this->assertSame(2, $audience['total']);
        $this->assertSame(1, $audience['direct_count']);
        $this->assertSame(1, $audience['matched_count']);
        $this->assertSame('direct-venue-user', $audience['direct'][0]['name']);
        $this->assertSame('conditions-venue-user', $audience['matched'][0]['name']);
        $this->assertNull($audience['matched'][0]['avatar']);
        $this->assertSame([], $audience['matched'][0]['positions']);
        $this->assertNull($audience['matched'][0]['experience_years']);

        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(\App\Presentation\Theming\ThemeResolver::class);
        \Illuminate\Support\Facades\View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        $this->actingAs($direct)->get(route('venues.show', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('Подписаны на эту площадку')
            ->assertSee('Подходят по условиям')
            ->assertSee('direct-venue-user')
            ->assertSee('conditions-venue-user')
            ->assertDontSee('Без данных профиля')
            ->assertDontSee('excluded-venue-user');

        $excludedSubscription = PlayIntent::query()->where('user_id', $excluded->id)->firstOrFail();
        $candidates = $this->actingAs($excluded)
            ->getJson(route('play-intents.recommendations', $excludedSubscription->id))
            ->assertOk()->json('data');
        $this->assertNotContains($venue->id, array_column($candidates, 'venue_id'));
        $this->actingAs($excluded)->postJson(route('play-intents.store'), [
            ...$this->payload($venue), 'include_similar_venues' => true,
            'excluded_venue_ids' => [$venue->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('excluded_venue_ids');
    }


    public function test_venue_subscriber_popup_hides_empty_groups_and_declines_count(): void
    {
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(\App\Presentation\Theming\ThemeResolver::class);
        \Illuminate\Support\Facades\View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);

        $this->get(route('venues.show', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('class="app-venue-interests-dialog__total-word">игроков</span>', false)
            ->assertDontSee('Подписаны на эту площадку')
            ->assertDontSee('Подходят по условиям');

        $user = User::factory()->create(['username' => 'single-login']);
        $this->actingAs($user)->postJson(route('play-intents.store'), [
            ...$this->payload($venue), 'cadence' => 'flexible', 'windows' => [],
            'show_on_venue' => false,
        ])->assertCreated();

        $this->get(route('venues.show', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('class="app-venue-interests-dialog__total-number">1</strong>', false)
            ->assertSee('class="app-venue-interests-dialog__total-word">игрок</span>', false)
            ->assertSee('Подписаны на эту площадку')
            ->assertDontSee('Подходят по условиям')
            ->assertSee('single-login')
            ->assertDontSee('Без данных профиля');

        // A second subscriber changes declension but does not create a
        // phantom "matched by conditions" group.
        $second = User::factory()->create(['username' => 'second-login']);
        $this->actingAs($second)->postJson(route('play-intents.store'), [
            ...$this->payload($venue), 'cadence' => 'flexible', 'windows' => [],
        ])->assertCreated();
        $this->get(route('venues.show', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('2 игрока')
            ->assertSee('class="app-venue-interests-dialog__total-word">игрока</span>', false)
            ->assertDontSee('Подходят по условиям');
    }


    public function test_subscription_hub_routes_filters_and_paginates_only_owned_records(): void
    {
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(\App\Presentation\Theming\ThemeResolver::class);
        \Illuminate\Support\Facades\View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        $user = User::factory()->create();
        $other = User::factory()->create();

        for ($i = 1; $i <= 15; $i++) {
            $status = match ($i) { 12 => 'paused', 13 => 'cancelled', 15 => 'completed', default => 'active' };
            PlayIntent::query()->create([
                'user_id' => $user->id,
                'name' => 'Subscription #'.$i,
                'cadence' => 'flexible',
                'status' => $status,
                'expires_at' => $i === 14 ? now()->subDay() : now()->addMonth(),
            ]);
        }
        PlayIntent::query()->create([
            'user_id' => $other->id,
            'name' => 'Another user secret',
            'status' => 'active',
            'expires_at' => now()->addMonth(),
        ]);

        $this->get('/account/subscriptions')->assertRedirect();
        $this->actingAs($user)->get('/account/play-interests')
            ->assertRedirect(route('play-intents.mine'))
            ->assertStatus(301);
        $this->get(route('play-intents.mine'))
            ->assertOk()
            ->assertSee('Мои подписки')
            ->assertSee('Тип подписки')
            ->assertSee('Все статусы')
            ->assertSee('data-subscriptions-pagination', false)
            ->assertDontSee('Твои подписки')
            ->assertDontSee('Хочу играть');

        $pageOne = $this->getJson(route('play-intents.index', ['type' => 'all', 'status' => 'all']))
            ->assertOk()
            ->assertJsonPath('meta.total', 15)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(10, 'data');
        $pageTwo = $this->getJson(route('play-intents.index', ['page' => 2, 'type' => 'play']))
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(5, 'data');
        $firstIds = array_column($pageOne->json('data'), 'id');
        $secondIds = array_column($pageTwo->json('data'), 'id');
        $this->assertCount(15, array_unique([...$firstIds, ...$secondIds]));
        $this->assertNotContains('Another user secret', array_column($pageOne->json('data'), 'name'));
        $this->assertSame(['play'], array_unique(array_column($pageOne->json('data'), 'subscription_type')));

        $this->getJson(route('play-intents.index', ['status' => 'active']))
            ->assertOk()->assertJsonPath('meta.total', 11);
        $this->getJson(route('play-intents.index', ['status' => 'expired']))
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Subscription #14')
            ->assertJsonPath('data.0.effective_status', 'expired');
        $this->getJson(route('play-intents.index', ['status' => 'paused']))
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Subscription #12');
        $this->getJson(route('play-intents.index', ['status' => 'cancelled']))
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson(route('play-intents.index', ['status' => 'completed']))
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson(route('play-intents.index', ['type' => 'unsupported']))
            ->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->getJson(route('play-intents.index', ['status' => 'unknown']))
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }



    public function test_expired_subscription_can_be_restored_without_losing_preferences_or_creating_a_copy(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);
        $id = $this->actingAs($user)->postJson(route('play-intents.store'), [
            ...$this->payload($venue),
            'name' => 'Баскетбол вечером',
            'show_on_venue' => false,
            'notify_matches' => true,
            'notify_offers' => false,
        ])->assertCreated()->json('data.id');

        $record = PlayIntent::findOrFail($id);
        $oldRequestId = $record->client_request_id;
        $record->forceFill(['expires_at' => now()->subDay()])->save();
        $this->getJson(route('play-intents.index', ['status' => 'expired']))
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.effective_status', 'expired');

        // The existing paused-resume action must not renew an expired record.
        $this->patchJson(route('play-intents.resume', $id))
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        // Browsing candidates is a read-only preview before renewal.
        $this->getJson(route('play-intents.recommendations', $id))
            ->assertOk()->assertJsonPath('meta.preview_only', true)
            ->assertJsonPath('data.0.venue_id', $venue->id);
        $this->assertTrue(PlayIntent::findOrFail($id)->expires_at->isPast());

        $this->patchJson(route('play-intents.restore', $id))
            ->assertOk()->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.effective_status', 'active')
            ->assertJsonPath('data.name', 'Баскетбол вечером')
            ->assertJsonPath('data.show_on_venue', false)
            ->assertJsonPath('data.notify_matches', true)
            ->assertJsonPath('data.notify_offers', false);

        $restored = PlayIntent::findOrFail($id);
        $this->assertTrue($restored->expires_at->between(now()->addDays(89), now()->addDays(91)));
        $this->assertSame($oldRequestId, $restored->client_request_id);
        $this->assertSame([2, 4], $restored->weekdays);
        $this->assertSame(['3x3', '5x5'], $restored->formats);
        $this->assertDatabaseCount('play_intents', 1);
        $this->getJson(route('play-intents.recommendations', $id))
            ->assertOk()->assertJsonPath('meta.preview_only', false);
        // Idempotency: no second renewal of an already active subscription.
        $this->patchJson(route('play-intents.restore', $id))
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_flexible_expiry_renews_for_thirty_days_and_other_owners_cannot_restore(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);
        $id = $this->actingAs($owner)->postJson(route('play-intents.store'), [
            ...$this->payload($venue), 'cadence' => 'flexible',
        ])->assertCreated()->json('data.id');
        PlayIntent::findOrFail($id)->forceFill(['expires_at' => now()->subDays(5)])->save();

        $this->actingAs($other)->patchJson(route('play-intents.restore', $id))->assertNotFound();
        $this->actingAs($owner)->patchJson(route('play-intents.restore', $id))
            ->assertOk()->assertJsonPath('data.effective_status', 'active');
        $this->assertTrue(PlayIntent::findOrFail($id)->expires_at->between(now()->addDays(29), now()->addDays(31)));

        $this->deleteJson(route('play-intents.destroy', $id))->assertOk();
        $this->patchJson(route('play-intents.restore', $id))
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_expired_one_time_subscription_requires_new_dates_before_renewal(): void
    {
        $owner = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);
        $oldDate = now()->subDays(2)->toDateString();
        $record = PlayIntent::query()->create([
            'user_id' => $owner->id, 'venue_id' => $venue->id,
            'cadence' => 'once', 'dates' => [$oldDate],
            'status' => 'active', 'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($owner)->patchJson(route('play-intents.restore', $record->id))
            ->assertUnprocessable()->assertJsonValidationErrors('dates');
        $this->assertTrue($record->fresh()->expires_at->isPast());

        $future = now()->addDays(5)->toDateString();
        $this->putJson(route('play-intents.update', $record), [
            'venue_id' => $venue->id, 'cadence' => 'once',
            'dates' => [$future], 'weekdays' => [], 'windows' => [],
        ])->assertOk()->assertJsonPath('data.effective_status', 'active')
            ->assertJsonPath('data.dates.0', $future);
        $this->assertDatabaseCount('play_intents', 1);
        $this->assertTrue($record->fresh()->expires_at->isFuture());
    }

}
