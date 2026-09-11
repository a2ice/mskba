<?php

namespace App\Modules\SportsSection\Application\UseCases;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\SportsSection\Application\Services\SportsSectionRules;
use App\Modules\SportsSection\Domain\Enums\SectionPricingTypeEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionAccessLevelEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionStatusEnum;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreateSportsSectionHandler
{
    public function __construct(private SportsSectionRules $rules) {}

    /** @param array<string, mixed> $data */
    public function handle(Actor $actor, array $data): SportsSection
    {
        $user = $actor->user;
        if ($user === null) {
            throw new SportsSectionException('Войдите в аккаунт, чтобы создать секцию.');
        }
        $user = $this->rules->assertCoach($user);
        $format = GameFormatEnum::from($data['game_format'] ?? GameFormatEnum::BASKETBALL_5X5->value);
        $pricingType = SectionPricingTypeEnum::from($data['pricing_type']);
        $amount = isset($data['single_session_price_minor']) ? (int) $data['single_session_price_minor'] : null;
        $this->rules->assertGameFormat($format);
        $this->rules->assertVenueCourt($data['primary_venue_id'] ?? null, $data['primary_venue_court_id'] ?? null);
        $this->rules->assertPricing($pricingType, $amount);

        return DB::transaction(function () use ($actor, $data, $user, $format, $pricingType, $amount): SportsSection {
            $base = Str::slug($data['name']) ?: 'section';
            $alias = $base;
            for ($suffix = 2; SportsSection::withTrashed()->where('alias', $alias)->exists(); $suffix++) {
                $alias = $base.'-'.$suffix;
            }
            $section = SportsSection::query()->create([
                ...$data,
                'created_by_actor_id' => $actor->id,
                'alias' => $alias,
                'status' => SportsSectionStatusEnum::DRAFT,
                'game_format' => $format,
                'pricing_type' => $pricingType,
                'single_session_price_minor' => $amount,
                'currency' => strtoupper($data['currency'] ?? 'RUB'),
            ]);
            $contract = Contract::query()->create([
                'family' => ContractFamilyEnum::MEMBERSHIP,
                'name' => "Владелец секции «{$section->name}»",
                'status' => ContractStatusEnum::ACTIVE,
                'starts_at' => now(),
                'assigned_by' => $user->id,
                'assigned_at' => now(),
                'assigner' => UserParticipationRoleAssignerEnum::USER,
            ]);
            $membership = $contract->membership()->create([
                'scope_type' => ContractMembershipScopeTypeEnum::SPORTS_SECTION,
                'scope_id' => $section->id,
                'user_id' => $user->id,
                'access_level' => SportsSectionAccessLevelEnum::OWNER,
                'sport_roles' => ['coach'],
            ]);
            $contract->permissions()->createMany(array_map(
                static fn (SportsSectionPermissionEnum $permission): array => ['permission' => $permission->value],
                SportsSectionPermissionEnum::cases(),
            ));
            $section->update(['head_coach_membership_id' => $membership->id]);

            return $section->load(['headCoachMembership.user', 'primaryVenue', 'primaryVenueCourt']);
        });
    }
}
