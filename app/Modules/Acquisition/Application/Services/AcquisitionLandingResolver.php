<?php

namespace App\Modules\Acquisition\Application\Services;

use App\Modules\Acquisition\Domain\Enums\AcquisitionLandingTypeEnum;
use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class AcquisitionLandingResolver
{
    public function url(AcquisitionCampaign $campaign): string
    {
        return match ($campaign->landing_type) {
            AcquisitionLandingTypeEnum::ONBOARDING => route('acquisition.join', ['campaignCode' => $campaign->public_code]),
            AcquisitionLandingTypeEnum::HOME => route('welcome'),
            AcquisitionLandingTypeEnum::VENUE => route('venues.show', $this->venue($campaign)->routeIdentifier()),
            AcquisitionLandingTypeEnum::EVENT => route('events.show', $this->event($campaign)->routeIdentifier()),
            AcquisitionLandingTypeEnum::SPORTS_SECTION => route('sports-sections.show', $this->section($campaign)),
        };
    }

    /** @return array{id: int|string, name: string}|null */
    public function selectedTarget(AcquisitionCampaign $campaign): ?array
    {
        if (! $campaign->landing_type?->needsTarget() || $campaign->landing_target_id === null) {
            return null;
        }

        $target = $this->target($campaign->landing_type, (int) $campaign->landing_target_id);

        if ($target === null) {
            return null;
        }

        return [
            'id' => $target->getKey(),
            'name' => (string) ($target->name ?? $target->title ?? ''),
        ];
    }

    /** @return array<int, array{id: int|string, name: string, meta: string}> */
    public function candidates(AcquisitionLandingTypeEnum $type, string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2 || ! $type->needsTarget()) {
            return [];
        }

        $like = '%'.mb_strtolower($query).'%';

        return match ($type) {
            AcquisitionLandingTypeEnum::VENUE => Venue::query()
                ->where('status', 'confirmed')
                ->whereRaw('LOWER(name) LIKE ?', [$like])
                ->orderBy('name')
                ->limit(20)
                ->get()
                ->map(fn (Venue $venue): array => [
                    'id' => $venue->id,
                    'name' => $venue->name,
                    'meta' => 'Площадка',
                ])->all(),
            AcquisitionLandingTypeEnum::EVENT => Event::query()
                ->where('visibility', 'public')
                ->whereIn('status', ['published', 'completed'])
                ->whereRaw('LOWER(title) LIKE ?', [$like])
                ->latest('starts_at')
                ->limit(20)
                ->get()
                ->map(fn (Event $event): array => [
                    'id' => $event->id,
                    'name' => $event->title,
                    'meta' => trim(($event->starts_at?->format('d.m.Y H:i') ?? '').' · Мероприятие', ' ·'),
                ])->all(),
            AcquisitionLandingTypeEnum::SPORTS_SECTION => SportsSection::query()
                ->where('status', 'active')
                ->whereRaw('LOWER(name) LIKE ?', [$like])
                ->orderBy('name')
                ->limit(20)
                ->get()
                ->map(fn (SportsSection $section): array => [
                    'id' => $section->id,
                    'name' => $section->name,
                    'meta' => 'Секция',
                ])->all(),
            default => [],
        };
    }

    private function target(AcquisitionLandingTypeEnum $type, int $id): ?Model
    {
        return match ($type) {
            AcquisitionLandingTypeEnum::VENUE => Venue::query()->where('status', 'confirmed')->find($id),
            AcquisitionLandingTypeEnum::EVENT => Event::query()
                ->where('visibility', 'public')
                ->whereIn('status', ['published', 'completed'])
                ->find($id),
            AcquisitionLandingTypeEnum::SPORTS_SECTION => SportsSection::query()->where('status', 'active')->find($id),
            default => null,
        };
    }

    private function venue(AcquisitionCampaign $campaign): Venue
    {
        return Venue::query()
            ->where('status', 'confirmed')
            ->findOrFail((int) $campaign->landing_target_id);
    }

    private function event(AcquisitionCampaign $campaign): Event
    {
        return Event::query()
            ->where('visibility', 'public')
            ->whereIn('status', ['published', 'completed'])
            ->findOrFail((int) $campaign->landing_target_id);
    }

    private function section(AcquisitionCampaign $campaign): SportsSection
    {
        return SportsSection::query()
            ->where('status', 'active')
            ->findOrFail((int) $campaign->landing_target_id);
    }
}
