<?php

namespace App\Modules\Contract\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Application\Services\BackofficeContractEditor;
use App\Modules\Contract\Application\Services\EntityContractManager;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Contract\Domain\Models\ContractEvidenceDocument;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ContractEvidenceController extends Controller
{
    public function store(Request $request, Contract $contract): RedirectResponse
    {
        $user = $request->user()?->canonical();
        abort_unless($user !== null && $this->isParticipant($user, $contract)
            && $contract->status === ContractStatusEnum::ACTIVE
            && $contract->verification_requirement === 'required', 403);

        $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ]);
        $file = $request->file('document');
        $path = $file->store('contract-evidence/'.$contract->id, 'local');

        try {
            DB::transaction(function () use ($contract, $user, $file, $path): void {
                $contract->evidenceDocuments()->create([
                    'uploaded_by_user_id' => $user->id,
                    'disk' => 'local',
                    'path' => $path,
                    'name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'mime' => $file->getMimeType(),
                    'size' => $file->getSize(),
                ]);
                $contract->verification()->updateOrCreate([], [
                    'status' => 'pending',
                    'verified_at' => null,
                    'reviewer_user_id' => null,
                    'decision_reason' => null,
                ]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return back()->with('status', 'Документ добавлен и отправлен на проверку.');
    }

    public function download(Request $request, Contract $contract, ContractEvidenceDocument $document): StreamedResponse
    {
        $user = $request->user()?->canonical();
        abort_unless($document->contract_id === $contract->id && $user !== null
            && ($this->isParticipant($user, $contract)
                || app(BackofficeContractEditor::class)->canView()), 403);
        abort_unless($document->disk === 'local' && Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download($document->path, $document->name);
    }

    private function isParticipant(User $user, Contract $contract): bool
    {
        $membership = $contract->membership;
        if ($membership !== null && in_array((int) $membership->user_id, $user->identityIds(), true)) {
            return true;
        }
        if ($contract->assigned_by !== null && in_array((int) $contract->assigned_by, $user->identityIds(), true)) {
            return true;
        }
        if ($membership === null) {
            return false;
        }
        $manager = app(EntityContractManager::class);
        $permission = $manager->managePermission($membership->scope_type);

        return $permission !== null && $manager->allows($user,
            $membership->scope_type, (int) $membership->scope_id, $permission);
    }
}
