<?php

namespace App\Modules\SportsSection\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Application\Services\SportsSectionAccess;
use App\Modules\SportsSection\Application\Services\SportsSectionRules;
use App\Modules\SportsSection\Domain\Enums\SportsSectionJoinRequestStatusEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionStatusEnum;
use App\Modules\SportsSection\Domain\Enums\TraineeMembershipStatusEnum;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\SportsSection\Domain\Models\SectionTraineeMembership;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\SportsSection\Domain\Models\SportsSectionJoinRequest;
use Illuminate\Support\Facades\DB;

final readonly class ManageSportsSectionJoinRequestHandler
{
    public function __construct(
        private SportsSectionAccess $access,
        private SportsSectionRules $rules,
        private ManageSectionTraineeHandler $trainees,
    ) {}

    public function submit(SportsSection $section, User $user): SportsSectionJoinRequest
    {
        $user = $this->rules->assertPlayer($user);

        return DB::transaction(function () use ($section, $user): SportsSectionJoinRequest {
            $section = SportsSection::query()->lockForUpdate()->findOrFail($section->id);
            if ($section->status !== SportsSectionStatusEnum::ACTIVE || ! $section->accepts_trainee_requests) {
                throw new SportsSectionException('Секция сейчас не принимает заявки.');
            }

            $identityIds = $user->identityIds();
            if (SectionTraineeMembership::query()
                ->where('sports_section_id', $section->id)
                ->whereIn('user_id', $identityIds)
                ->where('status', TraineeMembershipStatusEnum::ACTIVE->value)
                ->exists()) {
                throw new SportsSectionException('Вы уже занимаетесь в этой секции.');
            }

            if (SportsSectionJoinRequest::query()
                ->where('sports_section_id', $section->id)
                ->whereIn('user_id', $identityIds)
                ->where('status', SportsSectionJoinRequestStatusEnum::PENDING->value)
                ->exists()) {
                throw new SportsSectionException('Ваша заявка уже ожидает решения.');
            }

            return SportsSectionJoinRequest::query()->create([
                'sports_section_id' => $section->id,
                'user_id' => $user->id,
                'status' => SportsSectionJoinRequestStatusEnum::PENDING,
            ]);
        });
    }

    public function cancel(SportsSection $section, SportsSectionJoinRequest $request, User $user): SportsSectionJoinRequest
    {
        $user = $user->canonical();

        return DB::transaction(function () use ($section, $request, $user): SportsSectionJoinRequest {
            SportsSection::query()->lockForUpdate()->findOrFail($section->id);
            $request = SportsSectionJoinRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->sports_section_id !== $section->id || ! in_array($request->user_id, $user->identityIds(), true)) {
                throw new SportsSectionException('Эта заявка вам не принадлежит.');
            }
            if ($request->status !== SportsSectionJoinRequestStatusEnum::PENDING) {
                throw new SportsSectionException('Отменить можно только заявку на рассмотрении.');
            }

            $request->update(['status' => SportsSectionJoinRequestStatusEnum::CANCELLED]);

            return $request->refresh();
        });
    }

    public function respond(SportsSection $section, SportsSectionJoinRequest $request, User $issuer, string $action, ?string $reason = null): SportsSectionJoinRequest
    {
        return DB::transaction(function () use ($section, $request, $issuer, $action, $reason): SportsSectionJoinRequest {
            $section = SportsSection::query()->lockForUpdate()->findOrFail($section->id);
            $issuer = $issuer->canonical();
            if (! $this->access->allows($issuer, $section, SportsSectionPermissionEnum::MANAGE_TRAINEES)) {
                throw new SportsSectionException('Недостаточно прав для обработки заявок секции.');
            }

            $request = SportsSectionJoinRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->sports_section_id !== $section->id) {
                throw new SportsSectionException('Заявка не относится к выбранной секции.');
            }
            if ($request->status !== SportsSectionJoinRequestStatusEnum::PENDING) {
                throw new SportsSectionException('Эта заявка уже обработана.');
            }

            if ($action === 'accept') {
                $this->trainees->activate($section, $request->user()->firstOrFail()->canonical(), $issuer);
                $status = SportsSectionJoinRequestStatusEnum::ACCEPTED;
            } elseif ($action === 'reject') {
                $status = SportsSectionJoinRequestStatusEnum::REJECTED;
            } else {
                throw new SportsSectionException('Неизвестное действие с заявкой.');
            }

            $request->update([
                'status' => $status,
                'review_reason' => filled($reason) ? trim((string) $reason) : null,
                'reviewed_by_user_id' => $issuer->id,
                'reviewed_at' => now(),
            ]);

            return $request->refresh()->load(['user.profile', 'reviewedBy.profile']);
        });
    }
}
