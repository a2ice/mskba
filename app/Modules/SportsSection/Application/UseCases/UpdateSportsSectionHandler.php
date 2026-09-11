<?php

namespace App\Modules\SportsSection\Application\UseCases;

use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Application\Services\SportsSectionAccess;
use App\Modules\SportsSection\Application\Services\SportsSectionRules;
use App\Modules\SportsSection\Domain\Enums\SectionContactSourceEnum;
use App\Modules\SportsSection\Domain\Enums\SectionPricingTypeEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionStatusEnum;
use App\Modules\SportsSection\Domain\Enums\TrainingModeEnum;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use Illuminate\Support\Facades\DB;

final readonly class UpdateSportsSectionHandler
{
    public function __construct(private SportsSectionAccess $access, private SportsSectionRules $rules) {}

    /** @param array<string, mixed> $data */
    public function handle(SportsSection $section, User $issuer, array $data): SportsSection
    {
        return DB::transaction(function () use ($section, $issuer, $data): SportsSection {
            $section = SportsSection::query()->lockForUpdate()->findOrFail($section->id);
            if (! $this->access->allows($issuer->canonical(), $section, SportsSectionPermissionEnum::MANAGE)) {
                throw new SportsSectionException('Недостаточно прав для редактирования секции.');
            }

            $format = GameFormatEnum::from($data['game_format']);
            $pricing = SectionPricingTypeEnum::from($data['pricing_type']);
            $amount = isset($data['single_session_price_minor']) ? (int) $data['single_session_price_minor'] : null;
            $venueId = isset($data['primary_venue_id']) ? (int) $data['primary_venue_id'] : null;
            $courtId = isset($data['primary_venue_court_id']) ? (int) $data['primary_venue_court_id'] : null;
            $this->rules->assertGameFormat($format);
            $this->rules->assertVenueCourt($venueId, $courtId);
            $this->rules->assertPricing($pricing, $amount);

            $section->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => SportsSectionStatusEnum::from($data['status']),
                'training_mode' => TrainingModeEnum::from($data['training_mode']),
                'game_format' => $format,
                'primary_venue_id' => $venueId,
                'primary_venue_court_id' => $courtId,
                'pricing_type' => $pricing,
                'single_session_price_minor' => $amount,
                'currency' => strtoupper($data['currency'] ?? 'RUB'),
                'contact_source' => SectionContactSourceEnum::from($data['contact_source']),
                'contact_notes' => $data['contact_notes'] ?? null,
            ]);

            return $section->refresh();
        });
    }
}
