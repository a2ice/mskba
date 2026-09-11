<?php

namespace App\Modules\SportsSection\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\SportsSection\Application\Services\SportsSectionAccess;
use App\Modules\SportsSection\Application\UseCases\CreateSportsSectionHandler;
use App\Modules\SportsSection\Application\UseCases\UpdateSportsSectionHandler;
use App\Modules\SportsSection\Domain\Enums\SectionContactSourceEnum;
use App\Modules\SportsSection\Domain\Enums\SectionPricingTypeEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionStatusEnum;
use App\Modules\SportsSection\Domain\Enums\TrainingModeEnum;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\Services\MinorAmountParser;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class AccountSportsSectionController extends Controller
{
    public function index(Request $request): Response
    {
        $this->guardFeature();
        // Account listing queries the shared membership table because no concrete section is selected yet.
        $sectionIds = ContractMembership::query()
            ->where('scope_type', ContractMembershipScopeTypeEnum::SPORTS_SECTION->value)
            ->whereIn('user_id', $request->user()->canonical()->identityIds())
            ->whereHas('contract', fn ($query) => $query
                ->where('family', ContractFamilyEnum::MEMBERSHIP->value)
                ->where('status', ContractStatusEnum::ACTIVE->value)
                ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now())))
            ->pluck('scope_id');
        $sections = SportsSection::query()->whereKey($sectionIds)
            ->with(['featuredMedia', 'headCoachMembership.user.profile'])->orderBy('name')->paginate(20);

        return ThemeResolver::page('account.sports-sections.index', compact('sections'));
    }

    public function create(): Response
    {
        $this->guardFeature();

        return ThemeResolver::page('account.sports-sections.form', $this->formData());
    }

    public function store(Request $request, CurrentActorResolver $actors, CreateSportsSectionHandler $handler, MinorAmountParser $amounts): RedirectResponse
    {
        $this->guardFeature();
        $data = $this->validated($request, $amounts);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        try {
            $section = $handler->handle($actor, $data);
        } catch (SportsSectionException $exception) {
            return back()->withInput()->withErrors(['section' => $exception->getMessage()]);
        }

        return redirect()->route('account.sports-sections.edit', $section)->with('status', 'Секция создана.');
    }

    public function edit(Request $request, SportsSection $sportsSection, SportsSectionAccess $access): Response
    {
        $this->guardFeature();
        abort_unless($access->activeMemberships($sportsSection)->whereIn('user_id', $request->user()->canonical()->identityIds())->exists(), 403);
        $sportsSection->load([
            'media' => fn ($query) => $query->where('collection', 'sports_section_gallery')->orderByDesc('is_featured'),
            'contacts', 'primaryVenue.courts', 'primaryVenueCourt', 'headCoachMembership.user.profile',
            'coachMemberships.user.profile', 'coachMemberships.contract.permissions',
            'traineeMemberships.user.profile', 'pricingPlans',
            'trainingSessions' => fn ($query) => $query->with(['venue', 'venueCourt', 'participants.user.profile', 'coaches.user.profile', 'event'])->latest('starts_at'),
        ]);

        return ThemeResolver::page('account.sports-sections.form', $this->formData($sportsSection));
    }

    public function update(Request $request, SportsSection $sportsSection, UpdateSportsSectionHandler $handler, MinorAmountParser $amounts): RedirectResponse
    {
        $this->guardFeature();
        try {
            $handler->handle($sportsSection, $request->user(), $this->validated($request, $amounts));
        } catch (SportsSectionException $exception) {
            return back()->withInput()->withErrors(['section' => $exception->getMessage()]);
        }

        return back()->with('status', 'Секция обновлена.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, MinorAmountParser $amounts): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::enum(SportsSectionStatusEnum::class)],
            'training_mode' => ['required', Rule::enum(TrainingModeEnum::class)],
            'game_format' => ['required', Rule::in([GameFormatEnum::BASKETBALL_5X5->value, GameFormatEnum::STREETBALL_3X3->value, GameFormatEnum::STREETBALL_1X1->value])],
            'primary_venue_id' => ['nullable', 'integer', 'exists:venues,id'],
            'primary_venue_court_id' => ['nullable', 'integer', 'exists:venue_courts,id'],
            'pricing_type' => ['required', Rule::enum(SectionPricingTypeEnum::class)],
            'single_session_price' => ['nullable', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'size:3'],
            'contact_source' => ['required', Rule::enum(SectionContactSourceEnum::class)],
            'contact_notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $data['status'] ??= SportsSectionStatusEnum::DRAFT->value;
        $data['single_session_price_minor'] = filled($data['single_session_price'] ?? null)
            ? $amounts->parse((string) $data['single_session_price'], $data['currency'] ?? 'RUB')
            : null;
        unset($data['single_session_price']);

        return $data;
    }

    /** @return array<string, mixed> */
    private function formData(?SportsSection $section = null): array
    {
        return [
            'section' => $section,
            'statuses' => SportsSectionStatusEnum::cases(),
            'trainingModes' => TrainingModeEnum::cases(),
            'formats' => [GameFormatEnum::BASKETBALL_5X5, GameFormatEnum::STREETBALL_3X3, GameFormatEnum::STREETBALL_1X1],
            'pricingTypes' => SectionPricingTypeEnum::cases(),
            'contactSources' => SectionContactSourceEnum::cases(),
            'venues' => Venue::query()->with('courts')->orderBy('name')->get(),
        ];
    }

    private function guardFeature(): void
    {
        abort_unless(config('features.sports_sections.enabled'), 404);
    }
}
