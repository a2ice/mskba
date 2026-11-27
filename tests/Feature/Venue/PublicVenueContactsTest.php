<?php

namespace Tests\Feature\Venue;

use App\Modules\Venue\Application\Services\PublicVenueContacts;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class PublicVenueContactsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_projection_is_allowlisted_ordered_and_independent_of_verification(): void
    {
        $venue = $this->venue();
        $venue->contacts()->create(['type' => 'email', 'value' => 'office@example.test', 'label' => 'Администрация', 'is_public' => true, 'verified_at' => now()]);
        $venue->contacts()->create(['type' => 'phone', 'value' => '+79001234567', 'label' => 'Бронирование', 'is_public' => true, 'is_primary' => true, 'comment' => 'Internal note']);
        $venue->contacts()->create(['type' => 'email', 'value' => 'private@example.test', 'is_public' => false, 'verified_at' => now()]);
        $venue->contacts()->create(['type' => 'email', 'value' => 'archived@example.test', 'is_public' => true])->delete();
        $this->venue()->contacts()->create(['type' => 'email', 'value' => 'foreign@example.test', 'is_public' => true]);

        $projector = app(PublicVenueContacts::class);
        $this->assertSame([
            ['type' => 'phone', 'value' => '+79001234567', 'label' => 'Бронирование', 'href' => 'tel:+79001234567'],
            ['type' => 'email', 'value' => 'office@example.test', 'label' => 'Администрация', 'href' => 'mailto:office@example.test'],
        ], $projector->forVenue($venue));
        $venue->update(['is_published' => false]);
        $this->assertSame([], $projector->forVenue($venue));
    }

    public function test_public_block_renders_on_venue_and_court_in_both_templates(): void
    {
        $venue = $this->venue();
        $court = $venue->primaryCourt()->firstOrFail();
        $contact = $venue->contacts()->create([
            'type' => 'email', 'value' => 'booking+test@example.test',
            'label' => 'Отдел бронирования', 'is_public' => true,
            'comment' => 'INTERNAL_CONTACT_COMMENT_NOT_PUBLIC',
        ]);
        $venue->contacts()->create(['type' => 'email', 'value' => 'private-contact@example.test', 'is_public' => false]);
        $venue->contacts()->create(['type' => 'email', 'value' => 'deleted-contact@example.test', 'is_public' => true])->delete();

        foreach (['mskba_app', 'mskba_dark'] as $theme) {
            config()->set('themes.active', $theme);
            app()->forgetInstance(ThemeResolver::class);
            View::replaceNamespace('theme', resource_path('themes/'.$theme.'/views'));
            foreach ([
                route('venues.show', $venue->routeIdentifier()),
                route('venues.courts.show', [$venue->routeIdentifier(), $court->routeIdentifier()]),
            ] as $url) {
                $response = $this->get($url)->assertOk()
                    ->assertSee('Контакты площадки')
                    ->assertSee('Отдел бронирования')
                    ->assertSee('booking+test@example.test')
                    ->assertSee('href="mailto:booking%2Btest@example.test"', false)
                    ->assertDontSee('INTERNAL_CONTACT_COMMENT_NOT_PUBLIC')
                    ->assertDontSee('private-contact@example.test')
                    ->assertDontSee('deleted-contact@example.test');
                if ($theme === 'mskba_app') {
                    $response->assertSeeInOrder(['Хочешь здесь играть?', 'Контакты площадки']);
                }
            }
        }
        $contact->update(['is_public' => false]);
        $this->get(route('venues.show', $venue->routeIdentifier()))->assertOk()->assertDontSee('id="venue-public-contacts-title"', false);
    }

    private function venue(): Venue
    {
        return Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED, 'is_published' => true]);
    }
}
