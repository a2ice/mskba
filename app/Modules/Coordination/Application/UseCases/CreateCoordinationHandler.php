<?php

namespace App\Modules\Coordination\Application\UseCases;

use App\Modules\Coordination\Application\Services\PollOptionValueFactory;
use App\Modules\Coordination\Domain\Enums\CoordinationSessionStatusEnum;
use App\Modules\Coordination\Domain\Enums\PollResultsVisibilityEnum;
use App\Modules\Coordination\Domain\Enums\PollSelectionModeEnum;
use App\Modules\Coordination\Domain\Enums\PollStatusEnum;
use App\Modules\Coordination\Domain\Enums\PollSubjectTypeEnum;
use App\Modules\Coordination\Domain\Models\CoordinationSession;
use App\Modules\Identity\Domain\Models\Actor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateCoordinationHandler
{
    public function __construct(private readonly PollOptionValueFactory $optionValues) {}

    /** @param array<string, mixed> $data */
    public function handle(Actor $actor, array $data): CoordinationSession
    {
        if ($actor->user_id === null) {
            throw new InvalidArgumentException('Для создания опроса нужен аккаунт пользователя.');
        }

        $subjectType = PollSubjectTypeEnum::tryFrom((string) ($data['subject_type'] ?? ''));

        if ($subjectType === null) {
            throw new InvalidArgumentException('Неизвестный тип вариантов.');
        }

        $selectionMode = PollSelectionModeEnum::tryFrom((string) ($data['selection_mode'] ?? ''));

        if ($selectionMode === null) {
            throw new InvalidArgumentException('Неизвестный режим выбора.');
        }

        $closesAt = CarbonImmutable::parse((string) ($data['closes_at'] ?? ''));

        if ($closesAt->isPast()) {
            throw new InvalidArgumentException('Время завершения должно быть в будущем.');
        }

        $options = $this->optionValues->many($subjectType, $data['options'] ?? []);

        return DB::transaction(function () use (
            $actor,
            $closesAt,
            $data,
            $options,
            $selectionMode,
            $subjectType,
        ): CoordinationSession {
            $session = CoordinationSession::query()->create([
                'organizer_actor_id' => $actor->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => CoordinationSessionStatusEnum::OPEN,
            ]);

            $poll = $session->polls()->create([
                'question' => $data['question'],
                'subject_type' => $subjectType,
                'selection_mode' => $selectionMode,
                'results_visibility' => $data['results_visibility'] ?? PollResultsVisibilityEnum::AFTER_VOTE,
                'status' => PollStatusEnum::OPEN,
                'allows_suggestions' => (bool) ($data['allows_suggestions'] ?? false),
                'allows_vote_changes' => (bool) $data['allows_vote_changes'],
                'is_anonymous' => (bool) $data['is_anonymous'],
                'closes_at' => $closesAt,
            ]);

            foreach ($options as $position => $option) {
                $poll->options()->create([
                    'label' => $option->label,
                    'value' => $option->value,
                    'sort_order' => $position,
                    'is_active' => true,
                ]);
            }

            return $session->load(['polls.options', 'organizerActor.user']);
        });
    }
}
