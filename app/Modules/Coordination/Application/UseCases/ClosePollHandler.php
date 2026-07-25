<?php

namespace App\Modules\Coordination\Application\UseCases;

use App\Modules\Coordination\Application\Services\CoordinationAccess;
use App\Modules\Coordination\Domain\Enums\CoordinationSessionStatusEnum;
use App\Modules\Coordination\Domain\Enums\PollStatusEnum;
use App\Modules\Coordination\Domain\Events\PollChanged;
use App\Modules\Coordination\Domain\Models\CoordinationSession;
use App\Modules\Coordination\Domain\Models\Poll;
use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ClosePollHandler
{
    public function __construct(private readonly CoordinationAccess $access) {}

    public function handle(int $pollId, ?Actor $actor = null): Poll
    {
        $sessionId = (int) Poll::query()->findOrFail($pollId, ['session_id'])->session_id;

        $poll = DB::transaction(function () use ($pollId, $sessionId, $actor): Poll {
            /** @var CoordinationSession $session */
            $session = CoordinationSession::query()->lockForUpdate()->findOrFail($sessionId);

            if ($actor !== null && ! $this->access->canManage($session, $actor)) {
                throw new InvalidArgumentException('Закрыть опрос может только его создатель.');
            }

            /** @var Poll $poll */
            $poll = Poll::query()->lockForUpdate()->findOrFail($pollId);

            if ($poll->status === PollStatusEnum::CLOSED) {
                return $poll;
            }

            if ($poll->status !== PollStatusEnum::OPEN) {
                throw new InvalidArgumentException('Этот опрос нельзя закрыть.');
            }

            $closedAt = now();
            $poll->forceFill([
                'status' => PollStatusEnum::CLOSED,
                'closed_at' => $closedAt,
                'closed_by_actor_id' => $actor?->id,
            ])->save();
            $session->forceFill([
                'status' => CoordinationSessionStatusEnum::DECISION_PENDING,
                'closed_at' => null,
            ])->save();

            return $poll;
        });

        event(new PollChanged($pollId));

        return $poll;
    }
}
