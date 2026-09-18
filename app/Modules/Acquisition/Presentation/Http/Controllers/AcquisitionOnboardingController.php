<?php

namespace App\Modules\Acquisition\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Acquisition\Application\Services\AcquisitionTracker;
use App\Modules\Acquisition\Domain\Enums\AcquisitionPersonaEnum;
use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;
use App\Modules\Identity\Application\UseCases\UpdateUserParticipationRolesHandler;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class AcquisitionOnboardingController extends Controller
{
    public function show(
        Request $request,
        AcquisitionTracker $tracker,
        ?string $campaignCode = null,
    ): Response {
        $campaign = $this->campaign($campaignCode);

        if ($campaign !== null && ! $campaign->isAvailable()) {
            return ThemeResolver::page('onboarding.campaign-state', [
                'campaign' => $campaign,
                'campaignState' => $campaign->state(),
            ]);
        }

        $currentVisit = $tracker->currentVisit($request);
        $resumeCurrentVisit = $request->user() !== null
            && $request->boolean('resume')
            && $currentVisit !== null
            && $currentVisit->campaign_id === $campaign?->id
            && $currentVisit->visited_at?->gte(now()->subMinutes(10));

        $visit = $resumeCurrentVisit
            ? $tracker->attachCurrentUser($request, $currentVisit)
            : $tracker->capture($request, $campaign);

        $authenticatedUser = null;
        $activeRoleValues = [];

        if ($request->user() !== null) {
            $tracker->attachCurrentUser($request, $visit);
            $authenticatedUser = $request->user()->canonical();
            $authenticatedUser->load('participationRoles');
            $activeRoleValues = $authenticatedUser->participationRoles
                ->map(fn ($role): string => $role->role->value)
                ->all();
        }

        return ThemeResolver::page('onboarding.join', [
            'campaign' => $campaign,
            'visit' => $visit,
            'personas' => AcquisitionPersonaEnum::cases(),
            'selectedPersona' => $request->session()->get(AcquisitionTracker::SESSION_PERSONA),
            'authenticatedUser' => $authenticatedUser,
            'participationRoles' => UserParticipationRoleEnum::cases(),
            'activeRoleValues' => $activeRoleValues,
        ]);
    }

    public function updatePersona(Request $request, AcquisitionTracker $tracker): JsonResponse
    {
        $validated = $request->validate([
            'persona' => ['required', 'string', Rule::enum(AcquisitionPersonaEnum::class)],
        ]);
        $persona = AcquisitionPersonaEnum::from($validated['persona']);
        $visit = $tracker->setPersona($request, $persona);

        return response()->json([
            'status' => 'success',
            'persona' => $persona->value,
            'role' => $persona->registrationRole()?->value,
            'needs_profile_details' => $persona->needsProfileDetails(),
            'visit_id' => $visit?->id,
        ]);
    }

    public function updateRoles(
        Request $request,
        UpdateUserParticipationRolesHandler $handler,
    ): JsonResponse {
        $allowedRoles = implode(',', array_column(UserParticipationRoleEnum::cases(), 'value'));

        $request->validate([
            'roles' => ['required', 'array:'.$allowedRoles],
            'roles.*' => ['required', 'boolean'],
        ]);

        $selectedRoles = collect(UserParticipationRoleEnum::cases())
            ->filter(fn (UserParticipationRoleEnum $role): bool => $request->boolean('roles.'.$role->value))
            ->values()
            ->all();

        $user = $handler->handle($request->user()->canonical(), $selectedRoles);
        $activeRoles = collect(UserParticipationRoleEnum::cases())
            ->filter(fn (UserParticipationRoleEnum $role): bool => $user->hasActiveRole($role->value))
            ->values();

        return response()->json([
            'status' => 'success',
            'roles' => $activeRoles
                ->map(fn (UserParticipationRoleEnum $role): array => [
                    'value' => $role->value,
                    'label' => $role->label(),
                ])
                ->all(),
        ]);
    }

    public function verifyLocation(Request $request, AcquisitionTracker $tracker): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['granted', 'denied', 'unavailable'])],
            'latitude' => ['nullable', 'required_if:status,granted', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_if:status,granted', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        $result = $tracker->updateLocation(
            request: $request,
            status: $validated['status'],
            latitude: isset($validated['latitude']) ? (float) $validated['latitude'] : null,
            longitude: isset($validated['longitude']) ? (float) $validated['longitude'] : null,
            accuracy: isset($validated['accuracy']) ? (float) $validated['accuracy'] : null,
        );

        return response()->json($result);
    }

    public function success(Request $request, AcquisitionTracker $tracker): Response|RedirectResponse
    {
        if ($request->user() === null) {
            return redirect()->route('acquisition.join');
        }

        $visit = $tracker->attachCurrentUser($request);
        $user = $request->user()->canonical();
        $user->load('participationRoles');

        $activeRoles = collect(UserParticipationRoleEnum::cases())
            ->filter(fn (UserParticipationRoleEnum $role): bool => $user->hasActiveRole($role->value))
            ->values();

        return ThemeResolver::page('onboarding.success', [
            'activeRoles' => $activeRoles,
            'visit' => $visit,
        ]);
    }

    private function campaign(?string $campaignCode): ?AcquisitionCampaign
    {
        if ($campaignCode === null || trim($campaignCode) === '') {
            return null;
        }

        $campaign = AcquisitionCampaign::query()
            ->with('venue.location.address')
            ->where('public_code', trim($campaignCode))
            ->first();

        abort_if($campaign === null, 404);

        return $campaign;
    }
}
