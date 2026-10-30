<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\Participation\PlayerPositionEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Models\Participation\PlayerSelfAssessment;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AdminEditPlayerGameAttributesHandler
{
    /** @param array<string, mixed> $attributes */
    public function handle(User $actor, User $target, array $attributes, string $reason): AuditLog
    {
        app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Укажите основание изменения (10–500 символов).']);
        }
        $rules = [
            'positions' => ['required', 'array', 'max:5'],
            'positions.*' => ['required', 'distinct', Rule::enum(PlayerPositionEnum::class)],
            'self_assessment' => ['required', 'array:'.implode(',', array_keys(PlayerSelfAssessment::SKILLS))],
        ];
        foreach (array_keys(PlayerSelfAssessment::SKILLS) as $field) {
            $rules['self_assessment.'.$field] = ['nullable', 'integer', 'between:1,10'];
        }
        $valid = Validator::make($attributes, $rules)->validate();

        return DB::transaction(function () use ($actor, $target, $valid, $reason): AuditLog {
            $user = User::query()->whereKey($target->canonical()->id)->lockForUpdate()->firstOrFail();
            app(AdminEditPersonalProfileHandler::class)->authorize($actor, $user);
            if (! $user->hasActiveRole(UserParticipationRoleEnum::PLAYER->value)) {
                throw ValidationException::withMessages(['role' => 'Игровая роль отключена.']);
            }
            $profile = $user->playerProfile()->lockForUpdate()->first();
            $profile ??= $user->playerProfile()->create([]);
            $positions = $profile->positions()->pluck('position')->map(
                fn ($p): string => $p->value,
            )->sort()->values()->all();
            $self = $profile->selfAssessment()->lockForUpdate()->first();
            $beforeSkills = [];
            $afterSkills = [];
            foreach (array_keys(PlayerSelfAssessment::SKILLS) as $key) {
                $beforeSkills[$key] = $self?->$key;
                $new = $valid['self_assessment'][$key] ?? null;
                $afterSkills[$key] = $new === null || $new === '' ? null : (int) $new;
            }
            $afterPositions = $valid['positions'];
            sort($afterPositions);
            if ($positions === $afterPositions && $beforeSkills === $afterSkills) {
                throw ValidationException::withMessages(['game' => 'Игровые характеристики не изменились.']);
            }
            $profile->positions()->delete();
            foreach ($afterPositions as $value) {
                $profile->positions()->create(['position' => $value]);
            }
            $self ??= $profile->selfAssessment()->create([]);
            $self->fill($afterSkills)->save();

            return AuditLog::query()->create([
                'actor_id' => app(CurrentActorResolver::class)->resolveForRequest(request())?->id,
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'event' => 'admin_player_game_attributes_changed',
                'old_values' => ['positions' => $positions, 'self_assessment' => $beforeSkills],
                'new_values' => ['positions' => $afterPositions, 'self_assessment' => $afterSkills],
                'metadata' => ['reason' => $reason, 'player_profile_id' => $profile->id],
            ]);
        });
    }
}
