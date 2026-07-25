<?php

namespace App\Modules\Coordination\Application\UseCases;

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
    /** @param array<string, mixed> $data */
    public function handle(Actor $actor, array $data): CoordinationSession
    {
        if ($actor->user_id === null) {
            throw new InvalidArgumentException('Для создания опроса нужен аккаунт пользователя.');
        }

        $subjectType = PollSubjectTypeEnum::tryFrom((string) ($data['subject_type'] ?? ''));

        if ($subjectType !== PollSubjectTypeEnum::TEXT) {
            throw new InvalidArgumentException('Для этого типа вариантов пока нет редактора.');
        }

        $selectionMode = PollSelectionModeEnum::tryFrom((string) ($data['selection_mode'] ?? ''));

        if ($selectionMode === null) {
            throw new InvalidArgumentException('Неизвестный режим выбора.');
        }

        $closesAt = CarbonImmutable::parse((string) ($data['closes_at'] ?? ''));

        if ($closesAt->isPast()) {
            throw new InvalidArgumentException('Время завершения должно быть в будущем.');
        }

        $options = $this->normalizeTextOptions($data['options'] ?? []);

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
                'allows_suggestions' => false,
                'allows_vote_changes' => (bool) $data['allows_vote_changes'],
                'is_anonymous' => (bool) $data['is_anonymous'],
                'closes_at' => $closesAt,
            ]);

            foreach ($options as $position => $option) {
                $poll->options()->create([
                    'label' => $option,
                    'value' => ['value' => $option],
                    'sort_order' => $position,
                    'is_active' => true,
                ]);
            }

            return $session->load(['polls.options', 'organizerActor.user']);
        });
    }

    /**
     * @return array<int, string>
     */
    private function normalizeTextOptions(mixed $rawOptions): array
    {
        if (! is_array($rawOptions)) {
            throw new InvalidArgumentException('Добавьте варианты ответа.');
        }

        $options = array_map(
            static fn (mixed $option): string => trim((string) $option),
            array_values($rawOptions),
        );
        $unique = array_unique(array_map(
            static fn (string $option): string => mb_strtolower($option),
            $options,
        ));

        if (count($options) < 2 || in_array('', $options, true) || count($unique) !== count($options)) {
            throw new InvalidArgumentException('Нужно указать хотя бы два неповторяющихся варианта.');
        }

        return $options;
    }
}
