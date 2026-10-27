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

    // Kept only for compatibility with old bookmarks. Venue subscriptions
    // are now regular PlayIntent records edited through /account/play-intents.
    public function update(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Используй раздел «Мои подписки» для изменения подписки.',
        ], 410);
    }

    private function authorizeOwner(Request $request, VenueSubscription $subscription): void
    {
        abort_unless($request->user() !== null &&
            (int) $subscription->user_id === (int) $request->user()->canonical()->id, 404);
    }
}
