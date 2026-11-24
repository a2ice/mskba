<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class VenueMyInterestsFilterTest extends TestCase
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

    private function venue(string $name): Venue
    {
        return Venue::factory()->create([
            'name' => $name,
            'is_published' => true,
            'status' => VenueStatusEnum::CONFIRMED->value,
            'operational_status' => VenueOperationalStatusEnum::ACTIVE->value,
        ]);
    }

    private function interest(User $user, Venue $venue, string $name, array $overrides = []): PlayIntent
    {
        return PlayIntent::query()->create([
            'user_id' => $user->id,
            'venue_id' => $venue->id,
            'name' => $name,
            'status' => 'active',
            'favorite_generated' => false,
            'include_similar_venues' => false,
            'cadence' => 'flexible',
            'access_mode' => 'any',
            'venue_kind' => 'any',
            ...$overrides,
        ]);
    }

    public function test_venue_filter_displays_active_owned_interests_and_existing_wizard_link(): void
    {
        $owner = User::factory()->create();
        $venue = $this->venue('Любимая площадка');
        $this->interest($owner, $venue, 'Играть по вечерам');
        $this->interest($owner, $venue, 'Закрытый старый запрос', ['status' => 'paused']);
        $this->interest(User::factory()->create(), $venue, 'Чужой интерес');

        $this->actingAs($owner)->get(route('venues'))
            ->assertOk()
            ->assertSee('Есть ближайшие мероприятия')
            ->assertSee('data-venue-interest-filter', false)
            ->assertSee('data-venue-interest-toggle', false)
            ->assertSee('>Не выбрано</option>', false)
            ->assertSee('Играть по вечерам')
            ->assertDontSee('Закрытый старый запрос')
            ->assertDontSee('Чужой интерес')
            ->assertSee('data-subscription-create', false)
            ->assertSee('Создайте свою подборку площадок');
    }

    public function test_checked_interest_filter_uses_any_active_or_specific_owned_interest(): void
    {
        $owner = User::factory()->create();
        $first = $this->venue('Альфа площадка');
        $second = $this->venue('Бета площадка');
        $third = $this->venue('Гамма площадка');
        $firstIntent = $this->interest($owner, $first, 'Первая подписка');
        $this->interest($owner, $second, 'Вторая подписка');
        $this->interest($owner, $third, 'Истекшая подписка', ['expires_at' => now()->subDay()]);

        $this->actingAs($owner)->get(route('venues', ['my_interests' => 1]))
            ->assertOk()
            ->assertSee('Альфа площадка')
            ->assertSee('Бета площадка')
            ->assertDontSee('Гамма площадка')
            ->assertSee('data-context-result-total="2"', false);

        $this->get(route('venues', ['my_interests' => 1, 'interest_id' => $firstIntent->id]))
            ->assertOk()
            ->assertSee('Альфа площадка')
            ->assertDontSee('Бета площадка')
            ->assertSee('data-context-result-total="1"', false);

        $this->getJson(route('venues', ['my_interests' => 1, 'interest_id' => 999999]))
            ->assertNotFound();
    }

    public function test_other_users_interests_cannot_be_selected_or_disclosed(): void
    {
        $venue = $this->venue('Площадка владельца');
        $other = User::factory()->create();
        $interest = $this->interest($other, $venue, 'Личный запрос другого пользователя');

        $this->actingAs(User::factory()->create())
            ->getJson(route('venues', ['my_interests' => 1, 'interest_id' => $interest->id]))
            ->assertNotFound();

        auth()->logout();
        $this->getJson(route('venues', ['my_interests' => 1]))->assertForbidden();
        $this->get(route('venues'))->assertOk()
            ->assertSee('Войдите, чтобы подобрать площадки по своим интересам');
    }

    public function test_interest_filter_restricts_map_and_infinite_scroll_count_consistently(): void
    {
        $user = User::factory()->create();
        $one = $this->venue('Площадка только по моему запросу');
        $this->venue('Другая не подходящая площадка');
        $this->interest($user, $one, 'Мои настройки');

        $this->actingAs($user)
            ->getJson(route('venues', ['my_interests' => 1, 'fragment' => 'items']))
            ->assertOk()->assertJsonPath('total', 1);

        $this->get(route('venues', ['my_interests' => 1, 'view' => 'map']))
            ->assertOk()->assertSee('data-context-result-total="1"', false);
    }
}
