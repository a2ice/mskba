<?php

namespace Tests\Feature\Tournament;

use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TournamentFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_user_can_open_create_form_without_an_existing_tournament(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        $this->actingAs($owner)
            ->get(route('tournaments.create'))
            ->assertOk()
            ->assertSee('Новый турнир');
    }

    public function test_confirmed_user_can_create_tournaments_with_same_title_and_alias(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        foreach ([1, 2] as $number) {
            $this->actingAs($owner)->post(route('tournaments.store'), $this->payload())
                ->assertRedirect();
        }

        $tournaments = Tournament::query()->orderBy('id')->get();
        $this->assertCount(2, $tournaments);
        $this->assertSame($tournaments[0]->alias, $tournaments[1]->alias);
        $this->assertNotSame($tournaments[0]->routeIdentifier(), $tournaments[1]->routeIdentifier());
        $this->assertSame(TournamentStatusEnum::CONFIRMED, $tournaments[0]->status);
        $this->assertSame(GameFormatEnum::STREETBALL_3X3, $tournaments[0]->format);
        $this->get(route('tournaments.show', $tournaments[0]->routeIdentifier()))->assertOk();
    }

    public function test_unconfirmed_user_cannot_create_tournament(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);

        $this->actingAs($user)->post(route('tournaments.store'), $this->payload())
            ->assertSessionHas('error', 'Создавать турниры может только подтверждённый пользователь.');

        $this->assertDatabaseCount('tournaments', 0);
    }

    public function test_only_owner_can_manage_status_and_soft_delete_tournament(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $stranger = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($owner)->post(route('tournaments.store'), $this->payload());
        $tournament = Tournament::query()->firstOrFail();

        $this->actingAs($stranger)->put(route('tournaments.update', $tournament->routeIdentifier()), $this->payload('Чужая правка'))
            ->assertSessionHas('error', 'У вас нет права управлять этим турниром.');
        $this->assertSame('Кубок Москвы', $tournament->fresh()->title);

        $this->actingAs($owner)->patch(route('tournaments.status', $tournament->routeIdentifier()), [
            'status' => TournamentStatusEnum::CANCELLED->value,
            'status_comment' => 'Недостаточно команд',
        ])->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->assertSame(TournamentStatusEnum::CANCELLED, $tournament->fresh()->status);
        $this->assertSame('Недостаточно команд', $tournament->fresh()->status_comment);

        $this->actingAs($owner)->patch(route('tournaments.status', $tournament->routeIdentifier()), [
            'status' => TournamentStatusEnum::CONFIRMED->value,
        ])->assertSessionHas('error', 'Отменённый турнир нельзя вернуть в активный статус.');

        $this->actingAs($owner)->delete(route('tournaments.destroy', $tournament->routeIdentifier()))
            ->assertRedirect(route('tournaments.index'));
        $this->assertSoftDeleted('tournaments', ['id' => $tournament->id]);
    }

    public function test_tournament_alias_cannot_be_changed_after_creation(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($owner)->post(route('tournaments.store'), $this->payload());
        $tournament = Tournament::query()->firstOrFail();
        $routeIdentifier = $tournament->routeIdentifier();

        $payload = $this->payload('Новое название');
        $payload['alias'] = 'new-public-alias';

        $this->actingAs($owner)
            ->put(route('tournaments.update', $routeIdentifier), $payload)
            ->assertSessionHas('status');

        $this->assertSame('moscow-cup', $tournament->fresh()->alias);
        $this->assertSame($routeIdentifier, $tournament->fresh()->routeIdentifier());
    }

    /** @return array<string, mixed> */
    private function payload(string $title = 'Кубок Москвы'): array
    {
        return [
            'title' => $title,
            'alias' => 'moscow-cup',
            'starts_on' => today()->addWeek()->format('Y-m-d'),
            'ends_on' => today()->addWeeks(2)->format('Y-m-d'),
            'short_description' => 'Краткое описание',
            'full_description' => 'Полное описание турнира',
            'format' => GameFormatEnum::STREETBALL_3X3->value,
        ];
    }
}
