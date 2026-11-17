<?php

namespace App\Modules\VenueBooking\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCommissionPolicy;
use App\Modules\VenueBooking\Application\Services\VenueBookingBackofficeAccess;
use App\Modules\VenueBooking\Domain\Models\VenueRentalInvoiceTemplate;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

final class VenueRentalInvoiceTemplateController extends Controller
{
    public function index(
        Request $request, Venue $venue, VenueCommercialAccess $access,
        VenueBookingBackofficeAccess $backoffice,
    ): Response {
        $user = $request->user();
        abort_unless($user && $this->canAccess($user, $venue, $access, $backoffice), 403);
        $templates = VenueRentalInvoiceTemplate::query()
            ->where(function ($query) use ($venue, $user): void {
                $query->where('scope', 'global')
                    ->orWhere(fn ($q) => $q->where('venue_id', $venue->id)
                        ->where('scope', 'venue'))
                    ->orWhere(fn ($q) => $q->where('venue_id', $venue->id)
                        ->where('scope', 'agent')->where('owner_user_id', $user->canonical()->id));
            })->orderBy('priority')->orderBy('id')->get();

        return ThemeResolver::page('venues.invoice-templates', [
            'venue' => $venue,
            'templates' => $templates,
            'canGlobal' => $backoffice->canManage($user),
            'canVenue' => $access->allows($user, $venue, VenuePermissionEnum::MANAGE_BOOKING_POLICY),
            'canAgent' => $this->canAgent($user, $venue, $access),
        ]);
    }

    public function store(
        Request $request, Venue $venue, VenueCommercialAccess $access,
        VenueBookingBackofficeAccess $backoffice,
    ): RedirectResponse {
        $data = $this->validateData($request);
        abort_unless($this->canScope($request->user(), $venue, $data['scope'], $access, $backoffice), 403);
        VenueRentalInvoiceTemplate::query()->create($this->attributes($data, $venue, $request->user()->canonical()->id));

        return back()->with('status', 'Шаблон счёта создан.');
    }

    public function update(
        Request $request, Venue $venue, VenueRentalInvoiceTemplate $template,
        VenueCommercialAccess $access, VenueBookingBackofficeAccess $backoffice,
    ): RedirectResponse {
        abort_unless($this->canScope($request->user(), $venue, $template->scope, $access, $backoffice)
            && ($template->scope === 'global' || $template->venue_id === $venue->id)
            && ($template->scope !== 'agent' || $template->owner_user_id === $request->user()->canonical()->id), 403);
        $data = $this->validateData($request);
        abort_unless($data['scope'] === $template->scope, 422);
        $template->update($this->attributes($data, $venue, $request->user()->canonical()->id));

        return back()->with('status', 'Шаблон обновлён.');
    }

    public function destroy(
        Request $request, Venue $venue, VenueRentalInvoiceTemplate $template,
        VenueCommercialAccess $access, VenueBookingBackofficeAccess $backoffice,
    ): RedirectResponse {
        abort_unless($this->canScope($request->user(), $venue, $template->scope, $access, $backoffice)
            && ($template->scope === 'global' || $template->venue_id === $venue->id)
            && ($template->scope !== 'agent' || $template->owner_user_id === $request->user()->canonical()->id), 403);
        // Keep historic invoice snapshots and links, but stop selecting the template.
        $template->update(['enabled' => false, 'auto_send' => false]);

        return back()->with('status', 'Шаблон отключён.');
    }

    private function canAccess($user, Venue $venue, VenueCommercialAccess $access, VenueBookingBackofficeAccess $backoffice): bool
    {
        return $backoffice->canManage($user)
            || $access->allows($user, $venue, VenuePermissionEnum::MANAGE_BOOKING_POLICY)
            || $this->canAgent($user, $venue, $access);
    }

    private function canAgent($user, Venue $venue, VenueCommercialAccess $access): bool
    {
        if ($user === null || ! $access->allows($user, $venue, VenuePermissionEnum::CONFIRM_PAYMENTS)) {
            return false;
        }
        $agent = VenueCommissionPolicy::query()->where('venue_id', $venue->id)
            ->first()?->selectedAgent;
        return $agent !== null && $agent->is_active
            && (int) $agent->user_id === (int) $user->canonical()->id;
    }

    private function canScope($user, Venue $venue, string $scope, VenueCommercialAccess $access, VenueBookingBackofficeAccess $backoffice): bool
    {
        return match ($scope) {
            'global' => $backoffice->canManage($user),
            'venue' => $user !== null && $access->allows($user, $venue, VenuePermissionEnum::MANAGE_BOOKING_POLICY),
            'agent' => $this->canAgent($user, $venue, $access),
            default => false,
        };
    }

    /** @return array<string,mixed> */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scope' => ['required', 'in:global,venue,agent'],
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:2000'],
            'payment_methods' => ['required', 'array', 'min:1', 'max:3'],
            'payment_methods.*' => ['required', 'in:bank_transfer,cash,other', 'distinct'],
            'payment_instructions' => ['nullable', 'string', 'max:4000'],
            'due_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'priority' => ['required', 'integer', 'min:0', 'max:1000'],
            'auto_send' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
        ]);
    }

    /** @param array<string,mixed> $data
     *  @return array<string,mixed>
     */
    private function attributes(array $data, Venue $venue, int $userId): array
    {
        return [
            ...$data,
            'auto_send' => (bool) ($data['auto_send'] ?? false),
            'enabled' => (bool) ($data['enabled'] ?? true),
            'venue_id' => $data['scope'] === 'global' ? null : $venue->id,
            'owner_user_id' => $data['scope'] === 'agent' ? $userId : null,
        ];
    }
}
