<?php

namespace Tests\Feature\Team;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Team\Domain\Models\TeamJoinRequest;
use Database\Seeders\GameLifecycleDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TeamJoinRequestConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_requests_information_and_applicant_replies_without_rejection(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $owner = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $applicant = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();
        $team->update(['accepts_join_requests' => true]);

        $this->actingAs($applicant)->post(route('teams.join-requests.store', $team->routeIdentifier()))
            ->assertRedirect();
        $entry = TeamJoinRequest::query()->where('team_id', $team->id)->where('user_id', $applicant->id)->firstOrFail();
        $url = route('teams.join-requests.messages.store', [$team->routeIdentifier(), $entry->id]);

        // Applicants cannot initiate a conversation before a manager asks a question.
        $this->post($url, ['body' => 'Сообщение без запроса'])->assertUnprocessable();
        $this->assertDatabaseCount('team_join_request_messages', 0);

        $question = 'Расскажите о себе: где играете и откуда узнали про нас?';
        $this->actingAs($owner)->post($url, ['body' => $question])
            ->assertRedirect()->assertSessionHas('status', 'Вопрос отправлен кандидату. Ожидаем ответа.');
        $this->assertSame(TeamJoinRequestStatusEnum::AWAITING_RESPONSE, $entry->fresh()->status);
        $this->assertNull($entry->fresh()->review_reason);
        $this->assertDatabaseHas('team_join_request_messages', [
            'team_join_request_id' => $entry->id,
            'sender_user_id' => $owner->id,
            'body' => $question,
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $applicant->id,
            'title' => 'Уточнение по заявке в команду',
        ]);
        $this->get(route('teams.join-requests.index', $team->routeIdentifier()))
            ->assertOk()->assertSee('Ожидается ответ кандидата')->assertSee($question);

        // No duplicate manager prompt while the applicant has yet to reply.
        $this->post($url, ['body' => 'Второй вопрос'])->assertUnprocessable();
        $this->actingAs($applicant)->post(route('teams.join-requests.store', $team->routeIdentifier()))
            ->assertUnprocessable();
        $this->get(route('teams.show', $team->routeIdentifier()))
            ->assertOk()->assertSee($question)->assertSee('Отправить ответ');

        $answer = 'Играю в Москве три года. Нашёл команду через сайт.';
        $this->post($url, ['body' => $answer])->assertRedirect()
            ->assertSessionHas('status', 'Ваш ответ отправлен представителям команды.');
        $this->assertSame(TeamJoinRequestStatusEnum::PENDING, $entry->fresh()->status);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $owner->id,
            'title' => 'Ответ кандидата на заявку',
        ]);
        $this->actingAs($owner)->get(route('teams.join-requests.index', $team->routeIdentifier()))
            ->assertOk()->assertSee($answer)->assertSee($question)->assertSee('Запросить информацию');

        $this->patch(route('teams.join-requests.respond', [$team->routeIdentifier(), $entry->id]), [
            'action' => 'accept',
        ])->assertRedirect();
        $this->assertSame(TeamJoinRequestStatusEnum::ACCEPTED, $entry->fresh()->status);
        $this->post($url, ['body' => 'После принятия'])->assertUnprocessable();
        $this->assertDatabaseCount('team_join_request_messages', 2);
    }

    public function test_manager_can_reject_while_waiting_for_answer_and_candidate_cannot_reply_afterward(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $owner = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $applicant = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();
        $entry = TeamJoinRequest::query()->create([
            'team_id' => $team->id,
            'user_id' => $applicant->id,
            'status' => TeamJoinRequestStatusEnum::PENDING,
        ]);

        $url = route('teams.join-requests.messages.store', [$team->routeIdentifier(), $entry->id]);
        $this->actingAs($owner)->post($url, ['body' => 'Кто вы?'])->assertRedirect();
        $this->patch(route('teams.join-requests.respond', [$team->routeIdentifier(), $entry->id]), [
            'action' => 'reject',
            'review_reason' => 'Не подходит по требованиям',
        ])->assertRedirect();
        $this->assertSame(TeamJoinRequestStatusEnum::REJECTED, $entry->fresh()->status);
        $this->actingAs($applicant)->post($url, ['body' => 'Мой ответ'])->assertUnprocessable();
        $this->assertDatabaseCount('team_join_request_messages', 1);
    }

    public function test_messages_are_private_to_applicant_and_team_managers(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $owner = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $applicant = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $outsider = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();
        $entry = TeamJoinRequest::query()->create([
            'team_id' => $team->id,
            'user_id' => $applicant->id,
            'status' => TeamJoinRequestStatusEnum::PENDING,
        ]);
        $url = route('teams.join-requests.messages.store', [$team->routeIdentifier(), $entry->id]);
        $this->actingAs($owner)->post($url, ['body' => 'Закрытый вопрос кандидату'])->assertRedirect();

        $this->actingAs($outsider)->post($url, ['body' => 'Попытка вмешаться'])->assertForbidden();
        $this->get(route('teams.show', $team->routeIdentifier()))
            ->assertOk()->assertDontSee('Закрытый вопрос кандидату');
        $this->get(route('teams.join-requests.index', $team->routeIdentifier()))->assertForbidden();

        $this->actingAs($applicant)
            ->post(route('teams.join-requests.messages.store', ['team' => 'wrong-team', 'joinRequest' => $entry->id]), ['body' => 'Попытка'])
            ->assertNotFound();
        $this->assertDatabaseCount('team_join_request_messages', 1);

        $this->post($url, ['body' => str_repeat('x', 2001)])
            ->assertSessionHasErrorsIn('joinMessage'.$entry->id, 'body');
        $this->assertDatabaseCount('team_join_request_messages', 1);
    }
}
