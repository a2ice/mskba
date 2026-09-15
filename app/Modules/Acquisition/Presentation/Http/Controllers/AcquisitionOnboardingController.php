<?php

namespace App\Modules\Acquisition\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Acquisition\Application\Services\AcquisitionTracker;
use App\Modules\Acquisition\Domain\Enums\AcquisitionPersonaEnum;
use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;
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
        $visit = $tracker->capture($request, $campaign);

        return ThemeResolver::page('onboarding.join', [
            'campaign' => $campaign,
            'visit' => $visit,
            'personas' => AcquisitionPersonaEnum::cases(),
            'selectedPersona' => $request->session()->get(AcquisitionTracker::SESSION_PERSONA),
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

        return response()->json(['status' => 'success'] + $result);
    }

    public function success(Request $request, AcquisitionTracker $tracker): Response|RedirectResponse
    {
        if ($request->user() === null) {
            return redirect()->route('acquisition.join');
        }

        $visit = $tracker->attachCurrentUser($request);
        $personaValue = $request->session()->get(AcquisitionTracker::SESSION_PERSONA)
            ?? $visit?->persona?->value;
        $persona = is_string($personaValue)
            ? AcquisitionPersonaEnum::tryFrom($personaValue)
            : null;
        $persona ??= AcquisitionPersonaEnum::EXPLORE;

        return ThemeResolver::page('onboarding.success', [
            'persona' => $persona,
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

        abort_if($campaign === null || ! $campaign->isAvailable(), 404);

        return $campaign;
    }
}
