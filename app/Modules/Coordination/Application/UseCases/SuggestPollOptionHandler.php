<?php

namespace App\Modules\Coordination\Application\UseCases;

use App\Modules\Coordination\Application\Services\PollOptionValueFactory;
use App\Modules\Coordination\Domain\Enums\PollStatusEnum;
use App\Modules\Coordination\Domain\Enums\PollSubjectTypeEnum;
use App\Modules\Coordination\Domain\Events\PollChanged;
use App\Modules\Coordination\Domain\Models\Poll;
use App\Modules\Coordination\Domain\Models\PollOption;
use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SuggestPollOptionHandler
{
    public function __construct(
        private readonly PollOptionValueFactory $optionValues,
        private readonly VenueEventAvailability $availability,
    ) {}

    public function handle(int $pollId, User $user, mixed $rawOption): PollOption
    {
        if ($user->isBlocked()) {
            throw new InvalidArgumentException('Заблокированный пользователь не может предлагать варианты.');
        }

        $option = DB::transaction(function () use ($pollId, $rawOption, $user): PollOption {
            /** @var Poll $poll */
            $poll = Poll::query()->lockForUpdate()->findOrFail($pollId);

            if ($poll->status !== PollStatusEnum::OPEN || $poll->closes_at->lessThanOrEqualTo(now())) {
                throw new InvalidArgumentException('Голосование уже закрыто.');
            }

            if (! $poll->allows_suggestions) {
                throw new InvalidArgumentException('В этом опросе нельзя предлагать варианты.');
            }

            if ($poll->options()->where('is_active', true)->count() >= 20) {
                throw new InvalidArgumentException('В опросе уже достигнут лимит вариантов.');
            }

            $value = $this->optionValues->one($poll->subject_type, $rawOption);
            $this->assertSuggestionAvailable($poll, $value->value);
            $duplicate = $poll->options()
                ->where('is_active', true)
                ->get(['value'])
                ->contains(fn (PollOption $existing): bool => $existing->value === $value->value);

            if ($duplicate) {
                throw new InvalidArgumentException('Такой вариант уже есть в опросе.');
            }

            return $poll->options()->create([
                'label' => $value->label,
                'value' => $value->value,
                'sort_order' => (int) $poll->options()->max('sort_order') + 1,
                'is_active' => true,
                'proposed_by_user_id' => $user->id,
            ]);
        });

        event(new PollChanged($pollId));

        return $option;
    }

    /** @param array<string, mixed> $value */
    private function assertSuggestionAvailable(Poll $poll, array $value): void
    {
        $configuration = $poll->configuration ?? [];
        $duration = isset($configuration['duration_minutes'])
            ? (int) $configuration['duration_minutes']
            : null;

        if ($poll->subject_type === PollSubjectTypeEnum::TIME
            && isset($configuration['venue_id'], $configuration['date'], $value['time'])) {
            $venue = Venue::query()
                ->with(['schedule.intervals', 'schedule.exceptions.intervals'])
                ->findOrFail((int) $configuration['venue_id']);
            $timezone = $venue->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow');
            $startsAt = CarbonImmutable::parse($configuration['date'].' '.$value['time'], $timezone)->utc();
            $this->availability->resolveEndsAt($venue, $startsAt, $duration);
        }

        if ($poll->subject_type === PollSubjectTypeEnum::VENUE
            && isset($configuration['starts_at'], $value['venue_id'])) {
            $venue = Venue::query()
                ->with(['schedule.intervals', 'schedule.exceptions.intervals'])
                ->findOrFail((int) $value['venue_id']);
            $startsAt = CarbonImmutable::parse((string) $configuration['starts_at'])->utc();
            $this->availability->resolveEndsAt($venue, $startsAt, $duration);
        }
    }
}
