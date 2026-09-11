<?php

namespace App\Modules\SportsSection\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SportsSection\Domain\Enums\SectionContactSourceEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionStatusEnum;
use App\Modules\SportsSection\Domain\Enums\TrainingSessionStatusEnum;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class SportsSectionController extends Controller
{
    public function index(Request $request): Response
    {
        $this->guardFeature();
        $sections = SportsSection::query()
            ->where('status', SportsSectionStatusEnum::ACTIVE->value)
            ->with(['featuredMedia', 'headCoachMembership.user.profile', 'primaryVenue'])
            ->withCount(['traineeMemberships as active_trainees_count' => fn ($query) => $query->where('status', 'active')])
            ->orderBy('name')->paginate(18)->withQueryString();

        return ThemeResolver::page('sports-sections.index', compact('sections'));
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
