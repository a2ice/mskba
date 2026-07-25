<?php

namespace App\Modules\Coordination\Application\UseCases;

use App\Modules\Coordination\Application\Services\CoordinationAccess;
use App\Modules\Coordination\Domain\Enums\CoordinationSessionStatusEnum;
use App\Modules\Coordination\Domain\Enums\PollStatusEnum;
use App\Modules\Coordination\Domain\Models\CoordinationSession;
use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CancelCoordinationHandler
{
    public function __construct(private readonly CoordinationAccess $access) {}

    public function handle(int $sessionId, Actor $actor): CoordinationSession
    {
        return DB::transaction(function () use ($sessionId, $actor): CoordinationSession {
            /** @var CoordinationSession $session */
            $session = CoordinationSession::query()->lockForUpdate()->findOrFail($sessionId);

            if (! $this->access->canManage($session, $actor)) {
                throw new InvalidArgumentException('Отменить согласование может только его создатель.');
            }

            if ($session->status === CoordinationSessionStatusEnum::CANCELLED) {
                return $session;
            }

            if ($session->status === CoordinationSessionStatusEnum::COMPLETED) {
                throw new InvalidArgumentException('Принятое решение нельзя отменить.');
            }

            $cancelledAt = now();
            $session->forceFill([
                'status' => CoordinationSessionStatusEnum::CANCELLED,
                'cancelled_at' => $cancelledAt,
                'cancelled_by_actor_id' => $actor->id,
            ])->save();
            $session->polls()
                ->whereIn('status', [PollStatusEnum::DRAFT->value, PollStatusEnum::OPEN->value])
                ->update(['status' => PollStatusEnum::CANCELLED->value, 'closed_at' => $cancelledAt]);

            return $session;
        });
    }
}
