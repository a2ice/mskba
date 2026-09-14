<?php

namespace App\Modules\SportsSection\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\SportsSection\Application\Services\SportsSectionAccess;
use App\Modules\SportsSection\Application\UseCases\CreateSportsSectionHandler;
use App\Modules\SportsSection\Application\UseCases\UpdateSportsSectionHandler;
use App\Modules\SportsSection\Domain\Enums\SectionContactSourceEnum;
use App\Modules\SportsSection\Domain\Enums\SectionPricingTypeEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionFormatEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum;
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
        $identityIds = $request->user()->canonical()->identityIds();
        $membershipQuery = fn () => ContractMembership::query()
            ->where('scope_type', ContractMembershipScopeTypeEnum::SPORTS_SECTION->value)
            ->whereIn('user_id', $identityIds)
            ->whereHas('contract', fn ($query) => $query
                ->where('family', ContractFamilyEnum::MEMBERSHIP->value)
                ->where('status', ContractStatusEnum::ACTIVE->value)
                ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now())));

        $sectionIds = $membershipQuery()->pluck('scope_id');
        $applicationSectionIds = $membershipQuery()
            ->whereHas('contract.permissions', fn ($query) => $query->where('permission', SportsSectionPermissionEnum::MANAGE_TRAINEES->value))
            ->pluck('scope_id');
        $teamRelationSectionIds = $membershipQuery()
            ->whereHas('contract.permissions', fn ($query) => $query->where('permission', SportsSectionPermissionEnum::MANAGE->value))
            ->pluck('scope_id');
        $sections = SportsSection::query()->whereKey($sectionIds)
            ->with(['featuredMedia', 'headCoachMembership.user.profile'])->orderBy('name')->paginate(20);

        return ThemeResolver::page('account.sports-sections.index', compact('sections', 'applicationSectionIds', 'teamRelationSectionIds'));
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
        $targetYears = $this->extractTargetYears($data);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        try {
            $section = $handler->handle($actor, $data);
            $section->forceFill($targetYears)->save();
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
        $data = $this->validated($request, $amounts);
        $targetYears = $this->extractTargetYears($data);
        try {
            $section = $handler->handle($sportsSection, $request->user(), $data);
            $section->forceFill($targetYears)->save();
        } catch (SportsSectionException $exception) {
            return back()->withInput()->withErrors(['section' => $exception->getMessage()]);
        }

        return back()->with('status', 'Секция обновлена.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, MinorAmountParser $amounts): array
    {
        $currentYear = (int) now()->year;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::enum(SportsSectionStatusEnum::class)],
            'training_mode' => ['required', Rule::enum(TrainingModeEnum::class)],
            'game_format' => ['required', Rule::enum(SportsSectionFormatEnum::class)],
            'audience_mode' => ['required', Rule::in(['none', 'exact', 'range'])],
            'target_year' => ['nullable', 'integer', 'between:1900,'.$currentYear, 'required_if:audience_mode,exact', 'prohibited_unless:audience_mode,exact'],
            'target_year_from' => ['nullable', 'integer', 'between:1900,'.$currentYear, 'required_if:audience_mode,range', 'prohibited_unless:audience_mode,range'],
            'target_year_to' => ['nullable', 'integer', 'between:1900,'.$currentYear, 'required_if:audience_mode,range', 'prohibited_unless:audience_mode,range', 'gte:target_year_from'],
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

    /** @param array<string, mixed> $data
     *  @return array{target_year:?int,target_year_from:?int,target_year_to:?int}
     */
    private function extractTargetYears(array &$data): array
    {
        $mode = (string) ($data['audience_mode'] ?? 'none');
        $result = [
            'target_year' => $mode === 'exact' ? (int) $data['target_year'] : null,
            'target_year_from' => $mode === 'range' ? (int) $data['target_year_from'] : null,
            'target_year_to' => $mode === 'range' ? (int) $data['target_year_to'] : null,
        ];
        unset($data['audience_mode'], $data['target_year'], $data['target_year_from'], $data['target_year_to']);

        return $result;
    }

    /** @return array<string, mixed> */
    private function formData(?SportsSection $section = null): array
    {
        return [
            'section' => $section,
            'statuses' => SportsSectionStatusEnum::cases(),
            'trainingModes' => TrainingModeEnum::cases(),
            'formats' => SportsSectionFormatEnum::cases(),
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
