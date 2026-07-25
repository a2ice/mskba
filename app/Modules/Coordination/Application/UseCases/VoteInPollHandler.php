<?php

namespace App\Modules\Coordination\Application\UseCases;

use App\Modules\Coordination\Domain\Enums\PollSelectionModeEnum;
use App\Modules\Coordination\Domain\Enums\PollStatusEnum;
use App\Modules\Coordination\Domain\Models\Poll;
use App\Modules\Coordination\Domain\Models\PollBallot;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class VoteInPollHandler
{
    /** @param array<int, int> $optionIds */
    public function handle(int $pollId, User $user, array $optionIds): PollBallot
    {
        if ($user->isBlocked()) {
            throw new InvalidArgumentException('Заблокированный пользователь не может голосовать.');
        }

        $optionIds = array_values(array_unique(array_map('intval', $optionIds)));

        return DB::transaction(function () use ($pollId, $user, $optionIds): PollBallot {
            /** @var Poll $poll */
            $poll = Poll::query()->lockForUpdate()->findOrFail($pollId);

            if ($poll->status !== PollStatusEnum::OPEN || $poll->closes_at->lessThanOrEqualTo(now())) {
                throw new InvalidArgumentException('Голосование уже закрыто.');
            }

            $existingBallot = PollBallot::query()
                ->where('poll_id', $poll->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existingBallot !== null && ! $poll->allows_vote_changes) {
                throw new InvalidArgumentException('В этом опросе нельзя изменить голос.');
            }

            if ($optionIds === []) {
                throw new InvalidArgumentException('Выберите хотя бы один вариант.');
            }

            if ($poll->selection_mode === PollSelectionModeEnum::SINGLE && count($optionIds) !== 1) {
                throw new InvalidArgumentException('В этом опросе можно выбрать только один вариант.');
            }

            $validOptionIds = $poll->options()
                ->where('is_active', true)
                ->whereKey($optionIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            sort($validOptionIds);
            $expectedOptionIds = $optionIds;
            sort($expectedOptionIds);

            if ($validOptionIds !== $expectedOptionIds) {
                throw new InvalidArgumentException('Один из выбранных вариантов недоступен.');
            }

            $ballot = $existingBallot ?? PollBallot::query()->create([
                'poll_id' => $poll->id,
                'user_id' => $user->id,
            ]);
            $ballot = PollBallot::query()->lockForUpdate()->findOrFail($ballot->id);
            $ballot->selections()->delete();
            $ballot->selections()->createMany(
                array_map(fn (int $optionId): array => ['option_id' => $optionId], $optionIds),
            );
            $ballot->touch();

            return $ballot->load('selections.option');
        });
    }
}
