<?php

namespace Tests\Feature\Coordination;

use App\Modules\Coordination\Domain\Enums\PollSubjectTypeEnum;
use App\Modules\Coordination\Domain\Models\CoordinationSession;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CoordinationInterfaceContextSeparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_poll_management_actions_are_separated_from_public_voting(): void
    {
        $organizer = User::factory()->create();
        $participant = User::factory()->create();

        $this->actingAs($organizer)
            ->post(route('coordination.store'), [
                'flow_type' => 'single',
                'title' => 'Выбор времени тренировки',
                'description' => 'Проверка контекстов интерфейса.',
                'question' => 'Во сколько играем?',
                'subject_type' => PollSubjectTypeEnum::TEXT->value,
                'selection_mode' => 'single',
                'results_visibility' => 'always',
                'allows_vote_changes' => '1',
                'is_anonymous' => '0',
                'allows_suggestions' => '1',
                'options' => ['18:00', '20:00'],
                'publish_to_telegram' => '0',
                'closes_at' => now()->addHour()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect();

        $coordination = CoordinationSession::query()->firstOrFail();

        $this->actingAs($organizer)
            ->get(route('coordination.show', $coordination))
            ->assertOk()
            ->assertSee('Управление опросом')
            ->assertSee('Проголосовать')
            ->assertSee('Предложить вариант')
            ->assertDontSee('Закрыть голосование')
            ->assertDontSee('Отменить согласование');

        $this->get(route('coordination.management', $coordination))
            ->assertOk()
            ->assertSee('Закрыть голосование')
            ->assertSee('Отменить согласование');

        $this->actingAs($participant)
            ->get(route('coordination.show', $coordination))
            ->assertOk()
            ->assertSee('Проголосовать')
            ->assertDontSee('Управление опросом')
            ->assertDontSee('Закрыть голосование');

        $this->get(route('coordination.management', $coordination))
            ->assertForbidden();
    }
}
