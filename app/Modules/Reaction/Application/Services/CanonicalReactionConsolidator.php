<?php

namespace App\Modules\Reaction\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Reaction\Domain\Enums\ReactionActorTypeEnum;
use App\Modules\Reaction\Domain\Enums\ReactionSourceEnum;
use App\Modules\Reaction\Domain\Models\Reaction;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CanonicalReactionConsolidator
{
    public function consolidate(User $user): void
    {
        $canonical = $user->canonical();
        $identityIds = $canonical->identityIds();
        $telegramIds = TelegramAccount::query()
            ->whereIn('user_id', $identityIds)
            ->pluck('telegram_user_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $reactions = Reaction::query()
            ->where(function ($query) use ($identityIds, $telegramIds): void {
                $query->where(function ($query) use ($identityIds): void {
                    $query->where('actor_type', ReactionActorTypeEnum::USER)
                        ->whereIn('actor_id', array_map('strval', $identityIds));
                });

                if ($telegramIds !== []) {
                    $query->orWhere(function ($query) use ($telegramIds): void {
                        $query->where('actor_type', ReactionActorTypeEnum::TELEGRAM)
                            ->whereIn('actor_id', $telegramIds);
                    });
                }
            })
            ->lockForUpdate()
            ->get()
            ->groupBy(fn (Reaction $reaction): string => $reaction->subject_type->value.':'.$reaction->subject_id);

        foreach ($reactions as $group) {
            $this->consolidateSubject($canonical, $group);
        }
    }

    /** @param Collection<int, Reaction> $reactions */
    private function consolidateSubject(User $canonical, Collection $reactions): void
    {
        /** @var Reaction $winner */
        $winner = $reactions->sort(function (Reaction $left, Reaction $right): int {
            $time = ($right->source_occurred_at?->getTimestamp() ?? 0)
                <=> ($left->source_occurred_at?->getTimestamp() ?? 0);
            if ($time !== 0) {
                return $time;
            }

            $source = ($right->source === ReactionSourceEnum::WEB ? 1 : 0)
                <=> ($left->source === ReactionSourceEnum::WEB ? 1 : 0);
            if ($source !== 0) {
                return $source;
            }

            $sequence = ($right->source_sequence ?? 0) <=> ($left->source_sequence ?? 0);

            return $sequence !== 0 ? $sequence : $right->id <=> $left->id;
        })->first();

        Reaction::query()->whereKey($reactions->pluck('id'))->delete();

        DB::table('reactions')->insert([
            'subject_type' => $winner->subject_type->value,
            'subject_id' => $winner->subject_id,
            'actor_type' => ReactionActorTypeEnum::USER->value,
            'actor_id' => (string) $canonical->id,
            'user_id' => $canonical->id,
            'value' => $winner->value->value,
            'source' => $winner->source->value,
            'source_occurred_at' => $winner->source_occurred_at,
            'source_sequence' => $winner->source_sequence,
            'source_metadata' => $winner->source_metadata === null
                ? null
                : json_encode($winner->source_metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => $reactions->min('created_at') ?? now(),
            'updated_at' => $winner->updated_at,
        ]);
    }
}
