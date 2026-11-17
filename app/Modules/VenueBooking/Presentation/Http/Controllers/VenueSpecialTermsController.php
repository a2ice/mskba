<?php

namespace App\Modules\VenueBooking\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Domain\Models\VenueGroupOffer;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\Services\VenueSpecialTermsManager;
use App\Modules\VenueBooking\Application\UseCases\QuoteVenueBookingHandler;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingPolicyException;
use App\Presentation\Theming\ThemeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class VenueSpecialTermsController extends Controller
{
    public function index(Request $request, Venue $venue, VenueSpecialTermsManager $manager)
    {
        abort_unless($manager->canManage($request->user(), $venue), 403);
        $search = trim((string) $request->query('recipient_search', ''));
        $candidates = mb_strlen($search) >= 2 ? User::query()->whereNotNull('username')
            ->where('username', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%')
            ->orderBy('username')->limit(20)->get()->filter(fn (User $user) => $user->canonical()->isConfirmed()) : collect();

        return ThemeResolver::page('venues.special-terms', [
            'venue' => $venue, 'offers' => $venue->specialTerms()->with(['recipients', 'audits'])->latest()->paginate(15),
            'courts' => $venue->courts()->get(), 'candidates' => $candidates,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Venue $venue, VenueSpecialTermsManager $manager)
    {
        abort_unless($manager->canManage($request->user(), $venue), 403);
        $manager->create($venue, $request->user(), [
            ...$request->all(), 'auto_apply' => $request->boolean('auto_apply'),
        ]);

        return back()->with('status', 'Специальные условия сохранены как черновик.');
    }

    public function transition(Request $request, Venue $venue, VenueGroupOffer $offer, string $action, VenueSpecialTermsManager $manager)
    {
        $manager->transition($venue, $request->user(), $offer, $action);

        return back()->with('status', 'Статус условий обновлён.');
    }

    public function preview(Request $request, Venue $venue, VenueSpecialTermsManager $manager, QuoteVenueBookingHandler $quotes)
    {
        abort_unless($manager->canManage($request->user(), $venue), 403);
        $data = $request->validate([
            'recipient_id' => ['required', 'integer', 'exists:users,id'],
            'venue_court_id' => ['required', 'integer', Rule::exists('venue_courts', 'id')->where('venue_id', $venue->id)->whereNull('deleted_at')],
            'starts_at' => ['required', 'date'], 'duration_minutes' => ['required', 'integer', 'between:1,1440'],
            'scope' => ['required', Rule::enum(VenueBookingScopeEnum::class)],
            'coupon_code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);
        try {
            $quote = $quotes->handle($venue,
                CarbonImmutable::parse($data['starts_at'], $venue->schedule()->value('timezone') ?: config('app.timezone')),
                (int) $data['duration_minutes'], VenueBookingScopeEnum::from($data['scope']),
                User::query()->findOrFail($data['recipient_id']), $venue->courts()->findOrFail($data['venue_court_id']),
                $data['coupon_code'] ?? null);
        } catch (VenueBookingPolicyException $error) {
            throw ValidationException::withMessages(['preview' => $error->getMessage()]);
        }

        // Same handler as public quote; no usage consumed by preview.
        return back()->with('special_preview', [
            'amount_minor' => $quote->amountMinor, 'currency' => $quote->currency,
            'personal_discount' => $quote->personalDiscount,
        ]);
    }
}
