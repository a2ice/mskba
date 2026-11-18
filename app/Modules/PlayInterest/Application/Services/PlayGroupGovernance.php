<?php

namespace App\Modules\PlayInterest\Application\Services;

use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Domain\Models\PlayGroupCandidate;
use App\Modules\PlayInterest\Domain\Models\PlayGroupElection;
use App\Modules\PlayInterest\Domain\Models\PlayGroupVote;
use App\Modules\PlayInterest\Domain\Models\PlayOpportunity;
use App\Modules\Social\Application\Services\SocialActionLimiter;
use App\Modules\Social\Application\Services\SocialWallService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Scope-bound community governance. Election votes are independent from
 * ordinary Coordination polls and cannot be created by a group administrator.
 * The group row is the serialization point for candidate/vote/finalization races.
 */
final class PlayGroupGovernance
{
    public function __construct(
        private readonly SocialActionLimiter $actions,
        private readonly SocialWallService $wall,
        private readonly BackofficePermissionManager $permissions,
    ) {}

    private function locked(PlayOpportunity $group): PlayOpportunity
    {
        return PlayOpportunity::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
    }

    private function requireMember(PlayOpportunity $group, User $user): User
    {
        $canonical = $user->canonical();
        abort_unless($group->status === 'active' && $group->hasActiveMember($canonical), 403);
        return $canonical;
    }

    public function nominate(PlayOpportunity $group, User $user, string $reason): PlayGroupCandidate
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 20 || mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException('Обоснование должно содержать от 20 до 1000 символов.');
        }
        $user = $this->requireMember($group, $user);

        return $this->actions->run($user, 'play_opportunity', $group->id, 'admin_nomination', function () use ($group, $user, $reason): array {
            $group = $this->locked($group);
            $this->requireMember($group, $user);
            $round = PlayGroupElection::query()->where('opportunity_id', $group->id)
                ->where('status', 'open')->lockForUpdate()->first();

            if ($round === null) {
                $eligible = $group->electorate();
                if (count($eligible) < 2) {
                    throw new InvalidArgumentException('Для выборов необходимо как минимум два участника.');
                }
                $count = count($eligible);
                $round = PlayGroupElection::query()->create([
                    'opportunity_id' => $group->id,
                    'status' => 'open',
                    'electorate_user_ids' => $eligible,
                    'electorate_count' => $count,
                    'votes_required' => $count === 2 ? 2 : max(3, intdiv($count, 2) + 1),
                    'previous_admin_user_id' => $group->admin_user_id,
                    'earliest_close_at' => now()->addHours(24),
                    'closes_at' => now()->addDays(7),
                ]);
            }

            if (! in_array($user->id, $round->electorate_user_ids, true)) {
                throw new InvalidArgumentException('В текущих выборах могут участвовать только исходные члены группы.');
            }

            $candidate = $round->candidates()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($candidate !== null) {
                if ($candidate->withdrawn_at !== null) {
                    throw new InvalidArgumentException('Нельзя повторно выдвинуться в том же раунде.');
                }
                return ['changed' => false, 'value' => $candidate];
            }

            $candidate = $round->candidates()->create(['user_id' => $user->id, 'reason' => $reason]);
            $this->wall->appendSystem('play_opportunity', $group->id, $user, 'group_admin_nominated',
                ['election_id' => $round->id, 'candidate_id' => $candidate->id, 'reason' => $reason],
                $user);

            return ['changed' => true, 'value' => $candidate];
        });
    }

    public function vote(PlayOpportunity $group, User $user, int $candidateId): PlayGroupVote
    {
        $user = $this->requireMember($group, $user);
        return $this->actions->run($user, 'play_opportunity', $group->id, 'admin_vote', function () use ($group, $user, $candidateId): array {
            $group = $this->locked($group);
            $this->requireMember($group, $user);
            $round = PlayGroupElection::query()->where('opportunity_id', $group->id)
                ->where('status', 'open')->lockForUpdate()->firstOrFail();

            if (now()->greaterThanOrEqualTo($round->closes_at)) {
                $this->finalizeLocked($group, $round);
                throw new InvalidArgumentException('Время голосования истекло.');
            }
            if (! in_array($user->id, $round->electorate_user_ids, true)) {
                throw new InvalidArgumentException('В текущем раунде у вас нет права голоса.');
            }

            $candidate = $round->candidates()->whereNull('withdrawn_at')
                ->whereKey($candidateId)->firstOrFail();
            if (! $group->hasActiveMember($candidate->user)) {
                throw new InvalidArgumentException('Кандидат больше не участвует в группе.');
            }

            $current = $round->votes()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($current?->candidate_id === $candidate->id) {
                return ['changed' => false, 'value' => $current];
            }

            $previousId = $current?->candidate_id;
            if ($current !== null) {
                $current->update(['candidate_id' => $candidate->id]);
                $ballot = $current;
            } else {
                $ballot = $round->votes()->create([
                    'user_id' => $user->id, 'candidate_id' => $candidate->id,
                ]);
            }

            $this->wall->appendSystem('play_opportunity', $group->id, $user,
                $previousId === null ? 'group_admin_vote_cast' : 'group_admin_vote_changed', [
                    'election_id' => $round->id,
                    'previous_candidate_id' => $previousId,
                    'candidate_id' => $candidate->id,
                ], $candidate->user);

            $this->finalizeLocked($group, $round);

            return ['changed' => true, 'value' => $ballot];
        });
    }

    public function retractVote(PlayOpportunity $group, User $user): bool
    {
        $user = $this->requireMember($group, $user);
        return $this->actions->run($user, 'play_opportunity', $group->id, 'admin_vote', function () use ($group, $user): array {
            $group = $this->locked($group);
            $round = PlayGroupElection::query()->where('opportunity_id', $group->id)
                ->where('status', 'open')->lockForUpdate()->firstOrFail();
            $existing = $round->votes()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($existing === null) {
                return ['changed' => false, 'value' => false];
            }
            $previousId = $existing->candidate_id;
            $existing->delete();
            $this->wall->appendSystem('play_opportunity', $group->id, $user,
                'group_admin_vote_retracted', [
                    'election_id' => $round->id, 'candidate_id' => $previousId,
                ]);
            return ['changed' => true, 'value' => true];
        });
    }

    public function rename(PlayOpportunity $group, User $user, ?string $name): PlayOpportunity
    {
        $user = $this->requireMember($group, $user);
        $name = trim((string) $name);
        if (mb_strlen($name) > 80 || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
            throw new InvalidArgumentException('Название группы не должно превышать 80 символов и содержать служебные символы.');
        }
        $name = $name === '' ? null : $name;

        return $this->actions->run($user, 'play_opportunity', $group->id, 'group_rename', function () use ($group, $user, $name): array {
            $group = $this->locked($group);
            abort_unless($group->isAdministrator($user), 403);
            if ($group->custom_title === $name) {
                return ['changed' => false, 'value' => $group];
            }
            $previous = $group->displayTitle();
            $group->update(['custom_title' => $name]);
            $this->wall->appendSystem('play_opportunity', $group->id, $user, 'group_title_changed', [
                'previous' => $previous, 'current' => $group->displayTitle(), 'source' => 'group_admin',
            ]);
            return ['changed' => true, 'value' => $group];
        });
    }

    public function staffOverride(PlayOpportunity $group, User $operator, ?User $target, ?string $name, string $reason): PlayOpportunity
    {
        $operator = $operator->canonical();
        abort_unless($this->permissions->allows($operator, Permission::BACKOFFICE_ACCESS)
            && $this->permissions->allows($operator, Permission::BACKOFFICE_EVENT_MANAGE), 403);
        if (mb_strlen(trim($reason)) < 20) {
            throw new InvalidArgumentException('Укажите причину вмешательства (не менее 20 символов).');
        }
        return DB::transaction(function () use ($group, $operator, $target, $name, $reason): PlayOpportunity {
            $group = $this->locked($group);
            if ($target !== null) {
                $this->requireMember($group, $target);
            }
            $oldAdmin = $group->admin_user_id;
            $oldName = $group->custom_title;
            $newName = $name === null ? null : trim($name);
            if ($newName !== null && mb_strlen($newName) > 80) {
                throw new InvalidArgumentException('Слишком длинное название группы.');
            }
            $group->update([
                'admin_user_id' => $target?->canonical()->id,
                'admin_assigned_at' => $target ? now() : null,
                'admin_source' => 'staff_override',
                'custom_title' => $newName === '' ? null : $newName,
            ]);
            PlayGroupElection::query()->where('opportunity_id', $group->id)
                ->where('status','open')->update(['status'=>'cancelled', 'finished_at'=>now()]);
            $this->wall->appendSystem('play_opportunity', $group->id, $operator,
                'group_admin_staff_override', [
                    'previous_admin_user_id' => $oldAdmin,
                    'new_admin_user_id' => $group->admin_user_id,
                    'reason' => $reason,
                ], $target);
            if ($oldName !== $group->custom_title) {
                $this->wall->appendSystem('play_opportunity', $group->id, $operator,
                    'group_title_changed', ['previous' => $oldName ?: $group->generated_title,
                        'current' => $group->displayTitle(), 'source'=>'backoffice', 'reason'=>$reason]);
            }

            return $group;
        });
    }

    /** Scheduler-safe: no user quota, called only after election expiration/quorum. */
    public function finalizeDue(PlayOpportunity $group): ?PlayGroupElection
    {
        return DB::transaction(function () use ($group): ?PlayGroupElection {
            $group = $this->locked($group);
            $round = PlayGroupElection::query()->where('opportunity_id',$group->id)
                ->where('status','open')->lockForUpdate()->first();
            if ($round === null) {
                return null;
            }
            $this->finalizeLocked($group, $round);
            return $round->fresh();
        });
    }

    private function finalizeLocked(PlayOpportunity $group, PlayGroupElection $round): void
    {
        if ($round->status !== 'open') {
            return;
        }
        $eligible = array_intersect($round->electorate_user_ids, $group->electorate());
        if (count($eligible) < $round->votes_required) {
            $round->update(['status'=>'cancelled','finished_at'=>now()]);
            $this->wall->appendSystem('play_opportunity',$group->id,null,'group_election_cancelled',
                ['election_id'=>$round->id, 'reason'=>'membership_changed']);
            return;
        }

        $votes = $round->votes()->whereIn('user_id',$eligible)
            ->selectRaw('candidate_id, count(*) AS total')
            ->groupBy('candidate_id')->orderByDesc('total')->orderBy('candidate_id')->get();

        $winning = $votes->firstWhere('total', '>=', $round->votes_required);
        if ($winning !== null && now()->greaterThanOrEqualTo($round->earliest_close_at)) {
            $candidate = $round->candidates()->whereKey($winning->candidate_id)
                ->whereNull('withdrawn_at')->first();
            if ($candidate !== null && $group->hasActiveMember($candidate->user)) {
                $old = $group->admin_user_id;
                $group->update([
                    'admin_user_id'=>$candidate->user_id,
                    'admin_source'=>'election',
                    'admin_assigned_at'=>now(),
                ]);
                $round->update([
                    'status'=>'closed','winner_user_id'=>$candidate->user_id,'finished_at'=>now(),
                ]);
                $this->wall->appendSystem('play_opportunity', $group->id, $candidate->user,
                    $old === null ? 'group_admin_elected' : 'group_admin_replaced', [
                        'election_id'=>$round->id,
                        'votes'=>(int) $winning->total,
                        'required'=>$round->votes_required,
                        'previous_admin_user_id'=>$old,
                    ], $candidate->user);
                return;
            }
        }

        if (now()->greaterThanOrEqualTo($round->closes_at)) {
            $round->update(['status'=>'closed','finished_at'=>now()]);
            $this->wall->appendSystem('play_opportunity',$group->id,null,'group_election_closed',
                ['election_id'=>$round->id,'reason'=>'no_majority']);
        }
    }
}
