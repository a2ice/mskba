<?php

namespace App\Modules\PlayInterest\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PlayInterest\Application\Services\SubscriptionDeliveryChannels;
use App\Modules\PlayInterest\Application\Services\PlayIntentManager;
use App\Modules\PlayInterest\Application\Services\RecommendVenuesForIntent;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\PlayInterest\Presentation\Http\Requests\SavePlayIntentRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Modules\PlayInterest\Application\Services\VenueSubscriptionManager;
use App\Modules\PlayInterest\Domain\Models\VenueSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VenueSubscriptionController extends Controller
{
    public function availability(Request $request, SubscriptionDeliveryChannels $channels): JsonResponse
    {
        abort_unless($request->user() !== null, 401);
        return response()->json(['channels' => $channels->availability($request->user())])
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, VenueSubscription $venueSubscription, VenueSubscriptionManager $manager,
        SubscriptionDeliveryChannels $channels): JsonResponse
    {
        $this->authorizeOwner($request, $venueSubscription);
        $data = $request->validate([
            'active' => ['required', 'boolean'],
            'delivery_channels' => ['sometimes', 'array', 'min:1'],
            'delivery_channels.*' => ['string', 'in:in_app,telegram,email,phone'],
        ]);
        if (isset($data['delivery_channels'])) {
            $manager->setChannels($venueSubscription, $channels->validate($request->user(), $data['delivery_channels']));
        }
        $updated = DB::transaction(function () use ($manager, $request, $venueSubscription, $data): ?VenueSubscription {
            $updated = $manager->set($request->user(), (int) $venueSubscription->venue_id, (bool) $data['active']);
            if ($updated?->play_intent_id && $updated->playIntent) {
                $game = $updated->playIntent;
                // Cancel/restore the linked search together with its parent
                // subscription; do not leave hidden active searches behind.
                $game->forceFill(['status' => ! $data['active'] ? 'cancelled'
                    : (bool) data_get($updated->criteria, 'notify_matches', false) ? 'active' : 'paused'])->save();
            }
            return $updated;
        });
        return response()->json(['status' => $updated?->status, 'id' => $updated?->id])
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * The favorite-heart subscription opens the SAME full two-step editor as
     * a game subscription. Persist criteria independently until the user
     * explicitly opts into matching games.
     */
    public function updateDetails(
        SavePlayIntentRequest $request,
        VenueSubscription $venueSubscription,
        PlayIntentManager $games,
        SubscriptionDeliveryChannels $channels,
    ): JsonResponse {
        $this->authorizeOwner($request, $venueSubscription);
        $user = $request->user()->canonical();
        $data = $request->validated();
        $news = (bool) ($data['notify_venue_news'] ?? true);
        $matches = (bool) ($data['notify_matches'] ?? false);
        $venueId = isset($data['venue_id']) ? (int) $data['venue_id'] : null;

        if ($venueId === null && ! $matches) {
            throw ValidationException::withMessages([
                'venue_id' => 'Для поиска только по условиям включи уведомления о подходящих играх.',
            ]);
        }
        if ($news && $venueId === null) {
            throw ValidationException::withMessages([
                'notify_venue_news' => 'Для новостей площадки необходимо выбрать площадку.',
            ]);
        }
        if ($matches) {
            $data['game_delivery_channels'] = $channels->validate(
                $user, $data['game_delivery_channels'] ?? ['in_app']
            );
        }
        if ($news) {
            $data['venue_delivery_channels'] = $channels->validate(
                $user, $data['venue_delivery_channels'] ?? ['in_app']
            );
        }

        return DB::transaction(function () use ($venueSubscription, $user, $data, $matches, $news, $venueId, $games): JsonResponse {
            $locked = VenueSubscription::query()->whereKey($venueSubscription->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->user_id === (int) $user->id, 404);
            $linked = $locked->play_intent_id ? PlayIntent::query()->findOrFail($locked->play_intent_id) : null;

            // Removing the anchor converts the edited subscription into a
            // criteria-only game subscription, instead of discarding the form.
            if ($venueId === null) {
                $game = $games->save($user, [...$data, 'notify_venue_news' => false], $linked, false);
                $locked->forceFill(['status' => 'cancelled', 'notify_venue_news' => false,
                    'play_intent_id' => null])->save();
                return response()->json(['data' => [
                    'id' => $game->id, 'venue_id' => null, 'subscription_type' => 'play',
                ]]);
            }

            if (VenueSubscription::query()->where('user_id', $user->id)
                ->where('venue_id', $venueId)->whereKeyNot($locked->id)->exists()) {
                throw ValidationException::withMessages([
                    'venue_id' => 'Для выбранной площадки подписка уже существует.',
                ]);
            }
            $name = trim((string) ($data['name'] ?? ''));
            $name = $name !== '' ? $name : $locked->displayName();
            $name = mb_substr($name, 0, 80);
            $game = $linked;
            if ($matches || $linked !== null) {
                $game = $games->save($user, $data, $linked, false);
                if ($matches && $game->status === 'paused') {
                    $game->forceFill(['status' => 'active'])->save();
                }
                if (! $matches) {
                    // Retain saved criteria but do not leave an active search
                    // running once matching notifications are disabled.
                    $game->forceFill(['status' => 'paused'])->save();
                }
            }
            $locked->forceFill([
                'venue_id' => $venueId,
                'name' => $name,
                'criteria' => $data,
                'notify_venue_news' => $news,
                'delivery_channels' => $data['venue_delivery_channels'] ?? $locked->channels(),
                'play_intent_id' => $game?->id,
                'status' => ($news || $matches) ? 'active' : 'cancelled',
            ])->save();

            return response()->json(['data' => [
                'id' => $locked->id, 'venue_id' => $venueId,
                'subscription_type' => 'venue', 'name' => $name,
            ]])->header('Cache-Control', 'private, no-store');
        });
    }

    public function recommendations(
        Request $request,
        VenueSubscription $venueSubscription,
        RecommendVenuesForIntent $recommender,
    ): JsonResponse {
        $this->authorizeOwner($request, $venueSubscription);
        $criteria = $venueSubscription->criteria ?? [];
        $intent = $venueSubscription->playIntent;
        if ($intent === null) {
            $intent = new PlayIntent;
            $intent->fill([
                'venue_id' => $venueSubscription->venue_id,
                'status' => 'active',
                'cadence' => $criteria['cadence'] ?? 'flexible',
                'include_similar_venues' => (bool) ($criteria['include_similar_venues'] ?? false),
                'center_latitude' => $criteria['center_latitude'] ?? null,
                'center_longitude' => $criteria['center_longitude'] ?? null,
                'radius_km' => $criteria['radius_km'] ?? 10,
                'excluded_venue_ids' => $criteria['excluded_venue_ids'] ?? [],
                'venue_kind' => $criteria['venue_kind'] ?? 'any',
                'access_mode' => $criteria['access_mode'] ?? 'any',
                'amenity_preferences' => $criteria['amenity_preferences'] ?? [],
            ]);
        }

        // Recommendations are a preview even when this subscription does not
        // have game notifications enabled and its linked search is paused.
        $preview = clone $intent;
        $preview->status = 'active';
        if ($preview->expires_at?->isPast()) {
            $preview->expires_at = now()->addDay();
        }
        return response()->json(['data' => $recommender->handle($preview),
            'meta' => ['ranking' => 'geography_and_amenities_only',
                'availability_verified' => false]]);
    }

    private function authorizeOwner(Request $request, VenueSubscription $subscription): void
    {
        abort_unless($request->user() !== null &&
            (int) $subscription->user_id === (int) $request->user()->canonical()->id, 404);
    }
}
