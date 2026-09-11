<?php

namespace App\Modules\SportsSection\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\SportsSection\Domain\Enums\SectionContactSourceEnum;
use App\Modules\SportsSection\Domain\Enums\SectionPricingTypeEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionStatusEnum;
use App\Modules\SportsSection\Domain\Enums\TrainingModeEnum;
use App\Modules\SportsSection\Domain\Enums\TrainingSessionStatusEnum;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class SportsSectionController extends Controller
{
    public function index(Request $request): Response
    {
        $this->guardFeature();

        $query = SportsSection::query()
            ->where('status', SportsSectionStatusEnum::ACTIVE->value)
            ->with(['featuredMedia', 'headCoachMembership.user.profile', 'primaryVenue'])
            ->withCount(['traineeMemberships as active_trainees_count' => fn ($query) => $query->where('status', 'active')]);

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $needle = '%'.$search.'%';
            $query->where(fn ($builder) => $builder
                ->where('name', 'like', $needle)
                ->orWhere('description', 'like', $needle));
        }

        $trainingMode = (string) $request->query('training_mode', '');
        if (in_array($trainingMode, array_map(static fn (TrainingModeEnum $item): string => $item->value, TrainingModeEnum::cases()), true)) {
            $query->where('training_mode', $trainingMode);
        }

        $gameFormat = (string) $request->query('game_format', '');
        $allowedFormats = [
            GameFormatEnum::BASKETBALL_5X5,
            GameFormatEnum::STREETBALL_3X3,
            GameFormatEnum::STREETBALL_1X1,
        ];
        if (in_array($gameFormat, array_map(static fn (GameFormatEnum $item): string => $item->value, $allowedFormats), true)) {
            $query->where('game_format', $gameFormat);
        }

        $pricingType = (string) $request->query('pricing_type', '');
        if (in_array($pricingType, array_map(static fn (SectionPricingTypeEnum $item): string => $item->value, SectionPricingTypeEnum::cases()), true)) {
            $query->where('pricing_type', $pricingType);
        }

        $venueId = $request->integer('venue_id');
        if ($venueId > 0) {
            $query->where('primary_venue_id', $venueId);
        }

        $sections = $query->orderBy('name')->paginate(18)->withQueryString();
        $venues = Venue::query()
            ->whereIn('id', SportsSection::query()
                ->where('status', SportsSectionStatusEnum::ACTIVE->value)
                ->whereNotNull('primary_venue_id')
                ->select('primary_venue_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        return ThemeResolver::page('sports-sections.index', [
            'sections' => $sections,
            'trainingModes' => TrainingModeEnum::cases(),
            'formats' => $allowedFormats,
            'pricingTypes' => SectionPricingTypeEnum::cases(),
            'venues' => $venues,
        ]);
    }

    public function show(SportsSection $sportsSection): Response
    {
        $this->guardFeature();
        abort_unless($sportsSection->status === SportsSectionStatusEnum::ACTIVE, 404);
        $sportsSection->load([
            'media' => fn ($query) => $query->where('collection', 'sports_section_gallery')->orderByDesc('is_featured')->orderBy('sort_order'),
            'headCoachMembership.user.profile', 'headCoachMembership.user.contacts',
            'contacts' => fn ($query) => $query->where('is_public', true),
            'primaryVenue', 'primaryVenueCourt',
            'pricingPlans' => fn ($query) => $query->where('is_active', true)->orderBy('amount_minor'),
            'trainingSessions' => fn ($query) => $query->where('status', TrainingSessionStatusEnum::CONFIRMED->value)
                ->where('ends_at', '>=', now())->orderBy('starts_at')->limit(20),
        ]);
        $contacts = $sportsSection->contact_source === SectionContactSourceEnum::HEAD_COACH
            ? $sportsSection->headCoachMembership?->user?->contacts?->where('is_public', true) ?? collect()
            : $sportsSection->contacts;

        return ThemeResolver::page('sports-sections.show', ['section' => $sportsSection, 'contacts' => $contacts]);
    }

    private function guardFeature(): void
    {
        abort_unless(config('features.sports_sections.enabled'), 404);
    }
}
