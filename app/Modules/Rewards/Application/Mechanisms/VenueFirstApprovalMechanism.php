<?php

namespace App\Modules\Rewards\Application\Mechanisms;

use App\Modules\Finance\Domain\Enums\WalletOperationTypeEnum;
use App\Modules\Moderation\Domain\Enums\ModerationRequestStatusEnum;
use App\Modules\Moderation\Domain\Enums\ModerationTypeEnum;
use App\Modules\Moderation\Domain\Models\ModerationRequest;
use App\Modules\Rewards\Application\Contracts\RewardMechanism;
use App\Modules\Rewards\Application\Data\RewardMechanismContext;
use App\Modules\Rewards\Application\Data\RewardMechanismDecision;
use App\Modules\Rewards\Domain\Models\RewardVersion;
use App\Modules\Venue\Domain\Models\Venue;

final class VenueFirstApprovalMechanism implements RewardMechanism
{
    public function code(): string
    {
        return 'venue_first_approval';
    }

    public function label(): string
    {
        return 'Первая успешная модерация новой площадки';
    }

    public function walletOperationType(): WalletOperationTypeEnum
    {
        return WalletOperationTypeEnum::BONUS_GRANT;
    }

    public function parameterRules(): array
    {
        return [];
    }

    public function evaluate(
        RewardMechanismContext $context,
        RewardVersion $version,
    ): RewardMechanismDecision {
        $requestId = (int) ($context->payload['moderation_request_id'] ?? 0);

        $request = ModerationRequest::query()->find($requestId);

        if (
            $request === null
            || $request->type !== ModerationTypeEnum::VENUE
            || $request->status !== ModerationRequestStatusEnum::APPROVED
            || $request->venue_revision_id !== null
        ) {
            return RewardMechanismDecision::rejected('Это не первичная успешная модерация новой площадки.');
        }

        $alreadyApproved = ModerationRequest::query()
            ->where('type', ModerationTypeEnum::VENUE->value)
            ->where('subject_id', $request->subject_id)
            ->where('status', ModerationRequestStatusEnum::APPROVED->value)
            ->where('id', '<', $request->id)
            ->exists();

        if ($alreadyApproved) {
            return RewardMechanismDecision::rejected('Площадка уже проходила успешную модерацию.');
        }

        $venue = Venue::query()
            ->with('creatorActor.user')
            ->find($request->subject_id);

        if ($venue === null || $venue->canonical_venue_id !== null) {
            return RewardMechanismDecision::rejected('Площадка не подходит для начисления.');
        }

        $creator = $venue->creatorActor?->user?->canonical();

        if ($creator === null || $creator->trashed() || $creator->isBlocked()) {
            return RewardMechanismDecision::rejected('Автор площадки не найден или недоступен.');
        }

        return RewardMechanismDecision::eligible((int) $creator->id);
    }
}
