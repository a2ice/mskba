<?php

namespace Tests\Feature;

use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\EventParticipant;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class MskbaAppMyGamesTest extends TestCase
{
    use RefreshDatabase;

    private string $previousTheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTheme = (string) config('themes.active');
        config()->set('themes.active', 'mskba_app');
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

    private function participate(Event $event, User $user, string $status): void
    {
        // The generic EventFactory leaves the participation version unset;
        // real participation commands initialize it before creating a record.
        if ($event->participation_confirmation_version === null) {
            $event->update(['participation_confirmation_version' => 1]);
        }

        EventParticipant::query()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'role' => EventParticipantRoleEnum::PARTICIPANT,
            'status' => $status,
            'joined_at' => now(),
            'confirmation_version' => $event->participation_confirmation_version,
        ]);
    }

    public function test_game_training_application_immediately_appears_in_my_games_but_plain_training_does_not(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $gameTraining = Event::factory()->create([
            'type' => 'game_training', 'title' => 'Игровая тренировка — моя заявка',
        ]);
        $plainTraining = Event::factory()->create(['type' => 'training', 'title' => 'Обычная тренировка']);
        $otherGame = Event::factory()->create(['type' => 'game', 'title' => 'Чужая игра']);
        $this->participate($gameTraining, $user, EventParticipantStatusEnum::TENTATIVE->value);
        $this->participate($plainTraining, $user, EventParticipantStatusEnum::CONFIRMED->value);
        $this->participate($otherGame, $other, EventParticipantStatusEnum::CONFIRMED->value);

        $this->actingAs($user)->get(route('account.my-games'))
            ->assertOk()
            ->assertSee('Игровая тренировка — моя заявка')
            ->assertSee('Думаю / ожидаю решения')
            ->assertDontSee('Обычная тренировка')
            ->assertDontSee('Чужая игра');

        $this->get(route('account.my-games', ['scope' => 'requests']))->assertOk()
            ->assertSee('Игровая тренировка — моя заявка');
        $this->get(route('account.my-games', ['scope' => 'playing']))->assertOk()
            ->assertDontSee('Игровая тренировка — моя заявка');
    }

    public function test_confirmed_and_past_games_use_separate_statuses(): void
    {
        $user = User::factory()->create();
        $game = Event::factory()->create(['type' => 'game', 'title' => 'Текущая игра']);
        $past = Event::factory()->create([
            'type' => 'game_training', 'title' => 'Сыгранная тренировка',
            'starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addHour(),
        ]);
        $this->participate($game, $user, EventParticipantStatusEnum::CONFIRMED->value);
        $this->participate($past, $user, EventParticipantStatusEnum::CONFIRMED->value);

        $this->actingAs($user)->get(route('account.my-games', ['scope' => 'playing']))
            ->assertOk()->assertSee('Текущая игра')->assertSee('Участвую')
            ->assertDontSee('Сыгранная тренировка');
        $this->get(route('account.my-games', ['scope' => 'history']))
            ->assertOk()->assertSee('Сыгранная тренировка');
    }

    public function test_private_page_auth_alias_deduplication_and_invalid_filters(): void
    {
        $canonical = User::factory()->create();
        $alias = User::factory()->create(['canonical_user_id' => $canonical->id]);
        $game = Event::factory()->create(['type' => 'game_training', 'title' => 'Общая игровая тренировка']);
        $this->participate($game, $alias, EventParticipantStatusEnum::TENTATIVE->value);
        $this->get(route('account.my-games'))->assertRedirect();

        $response = $this->actingAs($canonical)->get(route('account.my-games'))->assertOk();
        $this->assertSame(1, substr_count($response->getContent(), 'Общая игровая тренировка</a>'));
        $this->get(route('account.my-games', ['scope' => 'wrong']))->assertSessionHasErrors('scope');
    }
}
