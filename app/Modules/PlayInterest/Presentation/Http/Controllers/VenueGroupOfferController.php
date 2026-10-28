<?php

namespace App\Modules\PlayInterest\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PlayInterest\Application\Services\VenueGroupOffers;
use App\Modules\PlayInterest\Domain\Models\VenueGroupOffer;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class VenueGroupOfferController extends Controller
{
    public function index(int $venue, Request $request, VenueGroupOffers $offers): Response|RedirectResponse
    {
        $item = $this->venue($venue);
        abort_unless($offers->canManage($request->user(), $item), 403);

        return ThemeResolver::page('venues.group-offers-manage', [
            'venue' => $item,
            'visibleOffers' => $offers->allForVenue($item),
            'offerCourts' => $item->courts()->orderBy('sort_order')->get(['id', 'name']),
            'venueTimezone' => $item->schedule()->value('timezone') ?: config('app.timezone', 'Europe/Moscow'),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(int $venue, Request $request, VenueGroupOffers $offers): RedirectResponse
    {
        $item = $this->venue($venue);
        abort_unless($offers->canManage($request->user(), $item), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'code' => ['required', 'string', 'min:5', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'discount_type' => ['required', Rule::in(['percent', 'fixed'])],
            'discount_value' => ['required', 'integer', 'min:1', 'max:100000000'],
            'valid_from' => ['required', 'date_format:Y-m-d\TH:i'],
            'valid_until' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'scope' => ['nullable', Rule::in(['whole', 'half_a', 'half_b'])],
            'venue_court_id' => ['nullable', 'integer', 'min:1'],
            'minimum_amount_minor' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'max_total_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'max_uses_per_account' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        // Never use a client supplied group/currency/timezone/author/role.
        $data['timezone'] = $item->schedule()->value('timezone') ?: config('app.timezone', 'Europe/Moscow');
        $offers->create($item, $request->user(), $data);

        return redirect()->route('account.venues.group-offers.index', ['venue' => $item->id])
            ->with('status', 'Промокод сохранён как черновик. Опубликуйте его для группы.');
    }

    public function transition(
        int $venue,
        VenueGroupOffer $offer,
        string $action,
        Request $request,
        VenueGroupOffers $offers,
    ): RedirectResponse {
        $item = $this->venue($venue);
        abort_unless($offers->canManage($request->user(), $item), 403);
        abort_unless($offer->venue_id === $item->id
            && $offer->group_key === VenueGroupOffer::groupKey($item), 404);
        abort_unless(in_array($action, ['publish', 'pause', 'revoke'], true), 404);

        $offers->transition($offer, $request->user(), $action);

        return redirect()->route('account.venues.group-offers.index', ['venue' => $item->id])
            ->with('status', 'Статус предложения обновлён.');
    }

    private function venue(int $id): Venue
    {
        return Venue::query()->whereKey($id)
            ->where('status', VenueStatusEnum::CONFIRMED->value)
            ->firstOrFail();
    }
}
