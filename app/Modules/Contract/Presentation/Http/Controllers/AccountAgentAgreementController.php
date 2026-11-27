<?php

namespace App\Modules\Contract\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueAgentAgreementService;
use App\Modules\Venue\Domain\Models\VenueAgentAgreement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class AccountAgentAgreementController extends Controller
{
    public function accept(Request $request, int $agreement, VenueAgentAgreementService $service): RedirectResponse
    {
        [$record, $user] = $this->recipientRecord($request, $agreement);
        $data = $request->validate([
            'accepted' => ['required', 'accepted'],
            'terms_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);
        $service->accept($record->venue, $record, $user, $data['terms_hash']);

        return redirect()->route('account.contracts')->with('status', 'Предложение принято. Договор ожидает проверки MSKBA.');
    }

    public function decline(Request $request, int $agreement, VenueAgentAgreementService $service): RedirectResponse
    {
        [$record, $user] = $this->recipientRecord($request, $agreement);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $service->close($record->venue, $record, $user, $data['reason']);

        return redirect()->route('account.contracts')->with('status', 'Предложение отклонено.');
    }

    /** @return array{VenueAgentAgreement,User} */
    private function recipientRecord(Request $request, int $id): array
    {
        $user = $request->user()?->canonical();
        abort_unless($user !== null && $user->isConfirmed() && ! $user->isBlocked() && ! $user->trashed(), 403);
        $record = VenueAgentAgreement::query()->whereIn('user_id', $user->identityIds())->with('venue')->findOrFail($id);
        abort_unless($record->venue !== null && $record->venue->allowsOperationalChanges()
            && $record->venue->canonical_venue_id === null, 409);

        return [$record, $user];
    }
}
