<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Venue\Application\Services\VenueOwnershipClaimDraftManager;
use App\Modules\Venue\Application\UseCases\SubmitVenueOwnershipClaimHandler;
use App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum;
use App\Modules\Venue\Domain\Exceptions\VenueOwnershipClaimException;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaim;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaimDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class VenueOwnershipClaimDraftController extends Controller
{
    public function save(Request $request, VenueOwnershipClaim $venueOwnershipClaim, VenueOwnershipClaimDraftManager $drafts, SubmitVenueOwnershipClaimHandler $submit): RedirectResponse
    {
        $this->authorizeClaim($request, $venueOwnershipClaim, true);
        $rules = VenueOwnershipClaimDraftManager::uploadRules();
        $rules['intent'] = ['required', 'in:save,submit'];
        if ($request->input('intent') === 'submit') {
            $rules['ownership_evidence'] = ['required', 'string', 'min:20', 'max:5000'];
        }
        $data = $request->validate($rules);
        try {
            $claim = $drafts->save($venueOwnershipClaim, $request->user(), (string) ($data['ownership_evidence'] ?? ''), $data['ownership_documents'] ?? []);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors(['ownership_documents' => 'Не удалось сохранить документы. Выберите файлы заново и попробуйте ещё раз.']);
        }
        if ($data['intent'] === 'submit') {
            try {
                $submit->handle($claim->venue, $request->user(), $claim->evidence);
            } catch (VenueOwnershipClaimException $exception) {
                return back()->with('error', $exception->getMessage().' Черновик и документы сохранены.');
            }
        }

        return redirect()->route('account.venue-ownership.show', $claim)
            ->with('status', $data['intent'] === 'submit' ? 'Заявка отправлена на проверку.' : 'Черновик и документы сохранены.');
    }

    public function download(Request $request, VenueOwnershipClaim $venueOwnershipClaim, VenueOwnershipClaimDocument $document): StreamedResponse
    {
        $this->authorizeClaim($request, $venueOwnershipClaim);
        abort_unless((int) $document->venue_ownership_claim_id === (int) $venueOwnershipClaim->id, 404);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download($document->path, $document->name, [
            'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, VenueOwnershipClaim $venueOwnershipClaim, VenueOwnershipClaimDocument $document): RedirectResponse
    {
        $this->authorizeClaim($request, $venueOwnershipClaim, true);
        DB::transaction(function () use ($venueOwnershipClaim, $document): void {
            Venue::query()->lockForUpdate()->findOrFail($venueOwnershipClaim->venue_id);
            $claim = VenueOwnershipClaim::query()->lockForUpdate()->findOrFail($venueOwnershipClaim->id);
            abort_unless($claim->status === VenueOwnershipClaimStatusEnum::DRAFT, 409);
            $document = $claim->documents()->lockForUpdate()->whereKey($document->id)->firstOrFail();
            $path = $document->path;
            $document->delete();
            DB::afterCommit(fn () => Storage::disk('local')->delete($path));
        });

        return back()->with('status', 'Документ удалён из черновика.');
    }

    private function authorizeClaim(Request $request, VenueOwnershipClaim $claim, bool $write = false): void
    {
        $user = $request->user()->canonical();
        abort_if($user->isBlocked() || $user->trashed(), 403);
        $applicant = $user->isSameIdentity($claim->applicant_user_id);
        $reviewer = $user->isConfirmed() && $user->system_role->atLeast(UserSystemRoleEnum::ADMIN);
        abort_unless($applicant || (! $write && $reviewer && $claim->submitted_at !== null), 403);
        if ($write) {
            abort_unless($claim->status === VenueOwnershipClaimStatusEnum::DRAFT, 409);
        }
    }
}
