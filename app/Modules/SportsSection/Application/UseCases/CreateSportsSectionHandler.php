<?php

namespace App\Modules\SportsSection\Application\UseCases;

use App\Modules\Contract\Application\Services\EntityContractManager;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\SportsSection\Application\Services\SportsSectionRules;
use App\Modules\SportsSection\Domain\Enums\SectionPricingTypeEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionFormatEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionStatusEnum;
use App\Modules\SportsSection\Domain\Enums\TrainingModeEnum;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreateSportsSectionHandler
{
    public function __construct(private SportsSectionRules $rules, private EntityContractManager $contracts) {}

    /** @param array<string, mixed> $data */
    public function handle(Actor $actor, array $data): SportsSection
    {
        $user = $actor->user;
        if ($user === null) {
            throw new SportsSectionException('Войдите в аккаунт, чтобы создать секцию.');
        }
        $user = $this->rules->assertCoach($user);
        $trainingMode = TrainingModeEnum::from($data['training_mode'] ?? TrainingModeEnum::GROUP->value);
        $format = SportsSectionFormatEnum::from($data['game_format'] ?? SportsSectionFormatEnum::BASKETBALL->value);
        $pricingType = SectionPricingTypeEnum::from($data['pricing_type']);
        $amount = isset($data['single_session_price_minor']) ? (int) $data['single_session_price_minor'] : null;
        $this->rules->assertSectionFormat($format);
        $this->rules->assertVenueCourt($data['primary_venue_id'] ?? null, $data['primary_venue_court_id'] ?? null);
        $this->rules->assertPricing($pricingType, $amount);

        return DB::transaction(function () use ($actor, $data, $user, $trainingMode, $format, $pricingType, $amount): SportsSection {
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
                'training_mode' => $trainingMode,
                'game_format' => $format,
                'pricing_type' => $pricingType,
                'single_session_price_minor' => $amount,
                'currency' => strtoupper($data['currency'] ?? 'RUB'),
            ]);
            $membership = $this->contracts->grantOwner(
                ContractMembershipScopeTypeEnum::SPORTS_SECTION,
                $section->id,
                $user,
                $user,
                "Владелец секции «{$section->name}»",
            );
            // A management contract is not a sports role. Keep the coaching
            // role explicitly for the coach roster and training snapshots.
            $membership->update(['sport_roles' => ['coach']]);
            $section->update(['head_coach_membership_id' => $membership->id]);

            return $section->load(['headCoachMembership.user', 'primaryVenue', 'primaryVenueCourt']);
        });
    }
}
