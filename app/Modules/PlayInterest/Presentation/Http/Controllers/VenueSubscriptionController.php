<?php

namespace App\Modules\PlayInterest\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PlayInterest\Application\Services\SubscriptionDeliveryChannels;
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
        $updated = $manager->set($request->user(), (int) $venueSubscription->venue_id, (bool) $data['active']);
        return response()->json(['status' => $updated?->status, 'id' => $updated?->id])
            ->header('Cache-Control', 'private, no-store');
    }

    private function authorizeOwner(Request $request, VenueSubscription $subscription): void
    {
        abort_unless($request->user() !== null &&
            (int) $subscription->user_id === (int) $request->user()->canonical()->id, 404);
    }
}
