<?php

namespace App\Modules\Coordination\Application\UseCases;

use App\Modules\Coordination\Application\Services\CoordinationAccess;
use App\Modules\Coordination\Domain\Enums\CoordinationSessionStatusEnum;
use App\Modules\Coordination\Domain\Enums\PollStatusEnum;
use App\Modules\Coordination\Domain\Models\CoordinationDecision;
use App\Modules\Coordination\Domain\Models\CoordinationSession;
use App\Modules\Coordination\Domain\Models\Poll;
use App\Modules\Coordination\Domain\Models\PollOption;
use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class DecideCoordinationHandler
{
    public function __construct(private readonly CoordinationAccess $access) {}

    public function handle(int $sessionId, int $optionId, Actor $actor): CoordinationDecision
    {
        return DB::transaction(function () use ($sessionId, $optionId, $actor): CoordinationDecision {
            /** @var CoordinationSession $session */
            $session = CoordinationSession::query()->lockForUpdate()->findOrFail($sessionId);

            if (! $this->access->canManage($session, $actor)) {
                throw new InvalidArgumentException('Принять решение может только создатель опроса.');
            }

            if ($session->decision()->exists()) {
                return $session->decision()->firstOrFail();
            }

            if ($session->status !== CoordinationSessionStatusEnum::DECISION_PENDING) {
                throw new InvalidArgumentException('Решение можно принять только после закрытия голосования.');
            }

            /** @var Poll $poll */
            $poll = $session->polls()->lockForUpdate()->firstOrFail();

            if ($poll->status !== PollStatusEnum::CLOSED) {
                throw new InvalidArgumentException('Сначала закройте голосование.');
            }

            /** @var PollOption|null $option */
            $option = $poll->options()->whereKey($optionId)->where('is_active', true)->first();

            if ($option === null) {
                throw new InvalidArgumentException('Выбранный вариант недоступен.');
            }

            $decision = CoordinationDecision::query()->create([
                'session_id' => $session->id,
                'poll_id' => $poll->id,
                'option_id' => $option->id,
                'decided_by_actor_id' => $actor->id,
                'decided_at' => now(),
            ]);
            $session->forceFill(['status' => CoordinationSessionStatusEnum::COMPLETED])->save();

            return $decision->load('option');
        });
    }
}
