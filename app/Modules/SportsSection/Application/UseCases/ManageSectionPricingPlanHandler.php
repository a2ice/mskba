<?php

namespace App\Modules\SportsSection\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Application\Services\SportsSectionAccess;
use App\Modules\SportsSection\Domain\Enums\SectionPricingTypeEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\SportsSection\Domain\Models\SectionPricingPlan;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use Illuminate\Support\Facades\DB;

final readonly class ManageSectionPricingPlanHandler
{
    public function __construct(private SportsSectionAccess $access) {}

    /** @param array<string, mixed> $data */
    public function store(SportsSection $section, User $issuer, array $data): SectionPricingPlan
    {
        return DB::transaction(function () use ($section, $issuer, $data): SectionPricingPlan {
            $section = SportsSection::query()->lockForUpdate()->findOrFail($section->id);
            if (! $this->access->allows($issuer->canonical(), $section, SportsSectionPermissionEnum::MANAGE_PRICING)) {
                throw new SportsSectionException('Недостаточно прав для управления тарифами секции.');
            }
            if ($section->pricing_type !== SectionPricingTypeEnum::PAID) {
                throw new SportsSectionException('Тарифы доступны только для платной секции.');
            }

            return $section->pricingPlans()->create([...$data, 'currency' => strtoupper($data['currency'] ?? $section->currency)]);
        });
    }

    public function toggle(SportsSection $section, SectionPricingPlan $plan, User $issuer, bool $active): SectionPricingPlan
    {
        if ($plan->sports_section_id !== $section->id || ! $this->access->allows($issuer->canonical(), $section, SportsSectionPermissionEnum::MANAGE_PRICING)) {
            throw new SportsSectionException('Недостаточно прав для изменения этого тарифа.');
        }
        $plan->update(['is_active' => $active]);

        return $plan->refresh();
    }
}
