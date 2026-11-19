<?php

namespace Tests\Feature\Venue;

use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class VenueChoiceWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['themes.active' => 'mskba_app']);
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        $this->withoutVite();
    }

    public function test_venue_choice_has_three_explicit_wizard_paths_and_return_actions(): void
    {
        $venue = Venue::factory()->create([
            'name' => 'Проверочная площадка',
            'status' => VenueStatusEnum::CONFIRMED,
            'is_published' => true,
        ]);

        $this->get(route('venues.show', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('data-venue-choice-event', false)
            ->assertSee('data-venue-choice-type', false)
            ->assertSee('data-venue-choice-event-venue', false)
            ->assertSee('data-venue-choice-event-next', false)
            ->assertSee('data-venue-choice-rental', false)
            ->assertSee('data-venue-choice-date', false)
            ->assertSee('data-venue-choice-interest', false)
            ->assertSee('data-venue-choice-interest-next data-play-intent-open', false)
            ->assertSee('data-venue-day-back', false)
            ->assertSee('data-quick-event-back', false)
            ->assertSee('data-quick-next', false)
            ->assertDontSee('data-venue-choice-favorite', false)
            ->assertDontSee('Выбрать дату</button>', false);
    }

    public function test_old_game_training_type_remains_readable_but_is_not_offered_for_new_events(): void
    {
        $this->assertSame(['game', 'training'], array_map(
            static fn (EventTypeEnum $type): string => $type->value,
            EventTypeEnum::availableForCreation(),
        ));
        $this->assertSame('Игровая тренировка', EventTypeEnum::GAME_TRAINING->label());

        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'is_published' => true,
        ]);

        $this->get(route('venues.show', $venue->routeIdentifier()))
            ->assertOk()
            ->assertDontSee('<option value="game_training"', false);
    }
}
