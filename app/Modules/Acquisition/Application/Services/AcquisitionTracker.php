<?php

namespace App\Modules\Acquisition\Application\Services;

use App\Modules\Acquisition\Domain\Enums\AcquisitionChannelEnum;
use App\Modules\Acquisition\Domain\Enums\AcquisitionPersonaEnum;
use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;
use App\Modules\Acquisition\Domain\Models\AcquisitionVisit;
use Illuminate\Http\Request;

final class AcquisitionTracker
{
    public const SESSION_VISIT_ID = 'acquisition.current_visit_id';
    public const SESSION_PERSONA = 'acquisition.persona';

    public function capture(Request $request, ?AcquisitionCampaign $campaign = null): AcquisitionVisit
    {
        $channel = $this->resolveChannel($request, $campaign);
        $existing = $this->currentVisit($request);

        if (
            $existing !== null
            && $existing->campaign_id === $campaign?->id
            && $existing->channel === $channel
            && $existing->visited_at?->gte(now()->subMinutes(10))
        ) {
            $this->attachCurrentUser($request, $existing);

            return $existing;
        }

        $user = $request->user()?->canonical();
        $visit = AcquisitionVisit::query()->create([
            'campaign_id' => $campaign?->id,
            'user_id' => $user?->id,
            'channel' => $channel,
            'source' => $this->input($request, 'utm_source'),
            'medium' => $this->input($request, 'utm_medium'),
            'campaign_name' => $this->input($request, 'utm_campaign') ?? $campaign?->name,
            'content' => $this->input($request, 'utm_content'),
            'term' => $this->input($request, 'utm_term'),
            'persona' => null,
            'landing_path' => mb_substr($request->getRequestUri(), 0, 2000),
            'referrer' => $this->header($request, 'referer', 2000),
            'location_status' => 'not_requested',
            'visited_at' => now(),
            'linked_at' => $user !== null ? now() : null,
        ]);

        $request->session()->put(self::SESSION_VISIT_ID, $visit->id);
        $request->session()->forget(self::SESSION_PERSONA);

        return $visit;
    }

    public function currentVisit(Request $request): ?AcquisitionVisit
    {
        $id = $request->session()->get(self::SESSION_VISIT_ID);

        if (! is_numeric($id)) {
            return null;
        }

        return AcquisitionVisit::query()
            ->with('campaign.venue.location.address')
            ->find((int) $id);
    }

    public function setPersona(Request $request, AcquisitionPersonaEnum $persona): ?AcquisitionVisit
    {
        $request->session()->put(self::SESSION_PERSONA, $persona->value);
        $visit = $this->currentVisit($request);

        if ($visit === null) {
            return null;
        }

        $this->attachCurrentUser($request, $visit);
        $visit->forceFill(['persona' => $persona])->save();

        return $visit->refresh();
    }

    public function attachCurrentUser(Request $request, ?AcquisitionVisit $visit = null): ?AcquisitionVisit
    {
        $user = $request->user()?->canonical();
        $visit ??= $this->currentVisit($request);

        if ($user === null || $visit === null) {
            return $visit;
        }

        if ((int) $visit->user_id !== (int) $user->id) {
            $visit->forceFill([
                'user_id' => $user->id,
                'linked_at' => now(),
            ])->save();
        }

        return $visit;
    }

    /**
     * @return array{status: string, distance_meters: int|null, accuracy_meters: float|null}
     */
    public function updateLocation(
        Request $request,
        string $status,
        ?float $latitude = null,
        ?float $longitude = null,
        ?float $accuracy = null,
    ): array {
        $visit = $this->currentVisit($request);

        if ($visit === null) {
            return [
                'status' => 'unavailable',
                'distance_meters' => null,
                'accuracy_meters' => $accuracy,
            ];
        }

        $this->attachCurrentUser($request, $visit);

        if ($status !== 'granted') {
            $normalized = in_array($status, ['denied', 'unavailable'], true)
                ? $status
                : 'unavailable';

            $visit->forceFill([
                'location_status' => $normalized,
                'location_accuracy_m' => $accuracy,
                'distance_to_venue_m' => null,
                'location_verified_at' => null,
            ])->save();

            return [
                'status' => $normalized,
                'distance_meters' => null,
                'accuracy_meters' => $accuracy,
            ];
        }

        $campaign = $visit->campaign;
        $address = $campaign?->venue?->location?->address;

        if (
            $campaign === null
            || ! $campaign->location_verification_enabled
            || $address?->latitude === null
            || $address->longitude === null
            || $latitude === null
            || $longitude === null
        ) {
            $visit->forceFill([
                'location_status' => 'unavailable',
                'location_accuracy_m' => $accuracy,
                'distance_to_venue_m' => null,
                'location_verified_at' => null,
            ])->save();

            return [
                'status' => 'unavailable',
                'distance_meters' => null,
                'accuracy_meters' => $accuracy,
            ];
        }

        $distance = $this->haversineMeters(
            (float) $address->latitude,
            (float) $address->longitude,
            $latitude,
            $longitude,
        );
        $radius = max(25, (int) $campaign->verification_radius_m);
        $accuracyValue = max(0.0, (float) ($accuracy ?? 0));

        if ($accuracyValue > 500) {
            $normalized = 'inaccurate';
        } elseif ($distance <= $radius + $accuracyValue) {
            $normalized = 'verified';
        } else {
            $normalized = 'mismatch';
        }

        $visit->forceFill([
            'location_status' => $normalized,
            'location_accuracy_m' => $accuracyValue,
            'distance_to_venue_m' => $distance,
            'location_verified_at' => $normalized === 'verified' ? now() : null,
        ])->save();

        return [
            'status' => $normalized,
            'distance_meters' => $distance,
            'accuracy_meters' => $accuracyValue,
        ];
    }

    private function resolveChannel(Request $request, ?AcquisitionCampaign $campaign): AcquisitionChannelEnum
    {
        if ($campaign?->channel instanceof AcquisitionChannelEnum) {
            return $campaign->channel;
        }

        $medium = strtolower((string) $request->query('utm_medium', ''));

        if (in_array($medium, ['cpc', 'ppc', 'paid', 'paid_search', 'context'], true)) {
            return AcquisitionChannelEnum::CONTEXT_ADS;
        }

        if (in_array($medium, ['social', 'organic_social', 'smm'], true)) {
            return AcquisitionChannelEnum::SOCIAL;
        }

        if (in_array($medium, ['partner', 'affiliate'], true)) {
            return AcquisitionChannelEnum::PARTNER;
        }

        if ($request->headers->has('referer')) {
            return AcquisitionChannelEnum::REFERRAL;
        }

        return AcquisitionChannelEnum::DIRECT;
    }

    private function input(Request $request, string $key): ?string
    {
        $value = trim((string) $request->query($key, ''));

        return $value === '' ? null : mb_substr($value, 0, 160);
    }

    private function header(Request $request, string $key, int $limit): ?string
    {
        $value = trim((string) $request->headers->get($key, ''));

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function haversineMeters(float $latA, float $lonA, float $latB, float $lonB): int
    {
        $earthRadius = 6_371_000;
        $latDelta = deg2rad($latB - $latA);
        $lonDelta = deg2rad($lonB - $lonA);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($lonDelta / 2) ** 2;

        return (int) round($earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}
