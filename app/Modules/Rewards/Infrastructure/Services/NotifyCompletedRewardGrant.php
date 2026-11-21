<?php

namespace App\Modules\Rewards\Infrastructure\Services;

use App\Modules\Notification\Application\Services\UserNotificationCounterStore;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Notification\Domain\Events\UserNotificationCreated;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Rewards\Domain\Models\RewardGrant;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\DB;

/**
 * Called only while holding the pending RewardGrant row lock. The grant's
 * pending -> completed transition and persistent notice share one transaction,
 * so duplicate events / retries cannot produce duplicate financial toasts.
 */
final class NotifyCompletedRewardGrant
{
    public function __construct(
        private readonly UserNotificationCounterStore $counterStore,
    ) {}

    public function create(RewardGrant $grant): void
    {
        [$title, $description] = match ($grant->mechanism_code) {
            'venue_first_approval' => [
                'Бонус за новую площадку',
                'Добавленная вами площадка прошла модерацию.',
            ],
            'referral_user_confirmed' => [
                'Бонус за приглашённого друга',
                'Приглашённый вами пользователь подтвердил аккаунт.',
            ],
            'referral_second_level_user_confirmed' => [
                'Бонус за приглашение второго уровня',
                'Приглашённый по вашей рекомендации пользователь подтвердил аккаунт.',
            ],
            default => [
                'Начислены бонусные баллы',
                'Вам начислено вознаграждение.',
            ],
        };

        $bodyLink = null;
        $venue = null;
        if ($grant->mechanism_code === 'venue_first_approval'
            && preg_match('/^venue:([1-9][0-9]*)$/D', $grant->business_fact_key, $matches) === 1) {
            $venue = Venue::query()->find((int) $matches[1]);
            if ($venue !== null && filled($venue->name)) {
                $prefix = 'Добавленная вами площадка ';
                $description = $prefix.$venue->name.' прошла модерацию.';
                $bodyLink = [
                    'text' => $venue->name,
                    'url' => route('venues.show', $venue->routeIdentifier(), absolute: false),
                    'offset' => strlen($prefix),
                ];
            }
        }

        $rubles = intdiv($grant->amount_minor, 100);
        $kopeks = $grant->amount_minor % 100;
        $amount = number_format($rubles, 0, ',', ' ')
            .','.str_pad((string) $kopeks, 2, '0', STR_PAD_LEFT).' ₽';

        $notification = UserNotification::query()->create([
            'user_id' => (int) $grant->recipient_user_id,
            'type' => UserNotificationTypeEnum::FINANCE,
            'status' => UserNotificationStatusEnum::NEW,
            'title' => $title,
            'body' => $description.' Начислено '.$amount.' бонусами.',
            'action_url' => route('account.wallet', [], false),
            'action_text' => 'Открыть кошелёк',
            'payload' => [
                'source' => 'rewards.grant.completed',
                'reward_grant_id' => (int) $grant->id,
                'reward_id' => (int) $grant->reward_id,
                'mechanism_code' => $grant->mechanism_code,
                'wallet_operation_id' => (int) $grant->wallet_operation_id,
                'amount_minor' => (int) $grant->amount_minor,
                'balance_type' => 'bonus',
                ...($bodyLink === null ? [] : ['venue_id' => (int) $venue->id, 'body_link' => $bodyLink]),
            ],
        ]);

        // Broadcast/Telegram/email must never see an uncommitted notification.
        // The persisted notice is still available at the next sync even if
        // realtime transport is unavailable when the transaction commits.
        DB::afterCommit(function () use ($notification): void {
            $this->counterStore->forget((int) $notification->user_id);
            UserNotificationCreated::dispatch((int) $notification->id);
        });
    }
}
