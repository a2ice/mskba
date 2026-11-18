<?php

namespace App\Modules\Social\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Social\Application\Exceptions\SocialActionRateLimitException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Transactional, strictly sliding 60-second quota for all user-initiated
 * communication actions (posts, RSVP and election votes) across scopes.
 *
 * The callback must write its business change in the *same* database transaction
 * and return ['changed'=>bool, 'value'=>mixed]. System timeline inserts are
 * part of that callback and do not consume additional tokens.
 */
final class SocialActionLimiter
{
    /**
     * @template T
     * @param callable():array{changed:bool,value:T} $callback
     * @return T
     */
    public function run(User $user, string $scope, int $scopeId, string $action, callable $callback): mixed
    {
        $canonicalId = (int) $user->canonical()->id;

        return DB::transaction(function () use ($canonicalId, $scope, $scopeId, $action, $callback): mixed {
            // Serializes *all* actions of this canonical user, including separate
            // HTTP requests targeting different events/groups.
            User::query()->whereKey($canonicalId)->lockForUpdate()->firstOrFail();

            $result = $callback();
            if (! $result['changed']) {
                return $result['value'];
            }

            $now = CarbonImmutable::now();
            $oldest = DB::table('social_action_logs')
                ->where('user_id', $canonicalId)
                ->where('created_at', '>', $now->subSeconds(60))
                ->orderBy('created_at')->first(['created_at']);

            $count = DB::table('social_action_logs')
                ->where('user_id', $canonicalId)
                ->where('created_at', '>', $now->subSeconds(60))->count();

            if ($count >= 3) {
                $retryAfter = $oldest === null ? 60 : max(
                    1, (int) ceil(60 - CarbonImmutable::parse($oldest->created_at)->diffInRealSeconds($now))
                );
                throw new SocialActionRateLimitException($retryAfter);
            }

            DB::table('social_action_logs')->insert([
                'user_id' => $canonicalId,
                'scope_type' => $scope,
                'scope_id' => $scopeId,
                'action_type' => $action,
                'created_at' => $now,
            ]);

            return $result['value'];
        }, 3);
    }
}
