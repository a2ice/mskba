<?php

namespace App\Modules\SportsSection\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\SportsSection\Application\Services\SportsSectionAccess;
use App\Modules\SportsSection\Application\Services\SportsSectionRules;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum;
use App\Modules\SportsSection\Domain\Enums\TraineeMembershipStatusEnum;
use App\Modules\SportsSection\Domain\Enums\TrainingSessionStatusEnum;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\SportsSection\Domain\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class CreateTrainingSessionHandler
{
    public function __construct(private SportsSectionAccess $access, private SportsSectionRules $rules) {}

    /** @param array<string, mixed> $data */
    public function handle(SportsSection $section, Actor $actor, array $data): TrainingSession
    {
        $user = $actor->user?->canonical();
        if ($user === null || ! $this->access->allows($user, $section, SportsSectionPermissionEnum::MANAGE_SESSIONS)) {
            throw new SportsSectionException('Недостаточно прав для создания занятия.');
        }
        $startsAt = CarbonImmutable::parse($data['starts_at']);
        $endsAt = CarbonImmutable::parse($data['ends_at']);
        if (! $startsAt->lessThan($endsAt)) {
            throw new SportsSectionException('Окончание занятия должно быть позже начала.');
        }

        return DB::transaction(function () use ($section, $actor, $data, $startsAt, $endsAt): TrainingSession {
            $section = SportsSection::query()->lockForUpdate()->findOrFail($section->id);
            $venueId = isset($data['venue_id']) ? (int) $data['venue_id'] : $section->primary_venue_id;
            $courtId = isset($data['venue_court_id']) ? (int) $data['venue_court_id'] : $section->primary_venue_court_id;
            $this->rules->assertVenueCourt($venueId, $courtId);
            $session = $section->trainingSessions()->create([
                'created_by_actor_id' => $actor->id,
                'starts_at' => $startsAt, 'ends_at' => $endsAt,
                'status' => TrainingSessionStatusEnum::PLANNED,
                'price_override_minor' => $data['price_override_minor'] ?? null,
                'venue_id' => $venueId, 'venue_court_id' => $courtId,
            ]);
            $participants = $section->traineeMemberships()->where('status', TraineeMembershipStatusEnum::ACTIVE->value)->get();
            $session->participants()->createMany($participants->map(fn ($membership): array => [
                'section_trainee_membership_id' => $membership->id, 'user_id' => $membership->user_id,
            ])->all());
            $coaches = $this->access->activeMemberships($section)->get();
            $session->coaches()->createMany($coaches->map(fn ($membership): array => [
                'contract_membership_id' => $membership->id, 'user_id' => $membership->user_id,
            ])->all());

            return $session->load(['section', 'participants.user', 'coaches.user', 'venue', 'venueCourt']);
        });
    }
}
