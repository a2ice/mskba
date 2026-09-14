<?php

namespace App\Modules\SportsSection\Presentation\Catalog;

use App\Modules\SportsSection\Domain\Enums\SectionPricingTypeEnum;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use Illuminate\Support\Collection;

final class SportsSectionCatalogPresenter
{
    /**
     * @param  Collection<int, SportsSection>  $sections
     * @return Collection<int, array<string, mixed>>
     */
    public function present(Collection $sections): Collection
    {
        return $sections->mapWithKeys(fn (SportsSection $section): array => [
            $section->id => $this->presentSection($section),
        ]);
    }

    /** @return array<string, mixed> */
    private function presentSection(SportsSection $section): array
    {
        $venue = $section->primaryVenue;
        $address = $venue?->location?->address;
        $teams = $section->teams
            ->filter(fn ($team): bool => ! $team->isTemporary() && $team->status === TeamStatusEnum::ACTIVE)
            ->values();
        $participantCount = (int) ($section->active_trainees_count ?? 0);

        return [
            'id' => $section->id,
            'name' => $section->name,
            'url' => route('sports-sections.show', $section),
            'image_url' => $section->featuredMedia?->publicUrl() ?: asset('images/venue-placeholder.png'),
            'description' => $section->description ?: 'Описание секции пока не добавлено.',
            'training_mode' => [
                'value' => $section->training_mode->value,
                'label' => $section->training_mode->label(),
            ],
            'format' => [
                'value' => $section->game_format->value,
                'label' => $section->game_format->label(),
            ],
            'venue' => $venue === null ? null : [
                'id' => $venue->id,
                'name' => $venue->name,
                'address' => $venue->raw_address ?: $address?->full_address,
                'latitude' => $address?->latitude,
                'longitude' => $address?->longitude,
            ],
            'teams' => $teams->map(fn ($team): array => [
                'id' => $team->id,
                'name' => $team->name,
                'url' => route('teams.show', $team->routeIdentifier()),
            ])->all(),
            'participant_count' => $participantCount,
            'participant_count_text' => $this->participantCountText($participantCount),
            'pricing_text' => $this->pricingText($section),
            'accepts_requests' => (bool) $section->accepts_trainee_requests,
            'recruiting' => (bool) $section->is_recruiting,
        ];
    }

    private function pricingText(SportsSection $section): string
    {
        if ($section->pricing_type === SectionPricingTypeEnum::FREE) {
            return 'Бесплатно';
        }

        if ($section->single_session_price_minor === null) {
            return 'Платно';
        }

        return number_format($section->single_session_price_minor / 100, 0, ',', ' ').' ₽ / занятие';
    }

    private function participantCountText(int $count): string
    {
        $modulo100 = $count % 100;
        $modulo10 = $count % 10;
        $label = $modulo100 >= 11 && $modulo100 <= 14
            ? 'участников'
            : match ($modulo10) {
                1 => 'участник',
                2, 3, 4 => 'участника',
                default => 'участников',
            };

        return "{$count} {$label}";
    }
}
