<?php

namespace App\Modules\Contract\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Application\Services\BackofficeContractEditor;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BackofficeContractVerificationController extends Controller
{
    public function review(Request $request, Contract $contract, BackofficeContractEditor $editor): RedirectResponse
    {
        abort_unless($editor->canManage(), 403);
        $data = $request->validate([
            'decision' => ['required', 'in:verified,rejected'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $user = $request->user()->canonical();
        $membership = $contract->membership;
        abort_if($membership !== null && in_array((int) $membership->user_id, $user->identityIds(), true), 403);
        abort_unless($contract->verification_requirement === 'required', 422);
        abort_if($data['decision'] === 'verified' && ! $contract->evidenceDocuments()->exists()
            && ! $contract->verification?->source_id, 422, 'Для подтверждения нужен документ или проверенное основание.');

        DB::transaction(function () use ($contract, $user, $data): void {
            $verification = $contract->verification()->updateOrCreate([], [
                'status' => $data['decision'],
                'reviewer_user_id' => $user->id,
                'verified_at' => $data['decision'] === 'verified' ? now() : null,
                'decision_reason' => trim($data['reason']),
            ]);

            DB::table('contract_change_logs')->insert([
                'contract_id' => $contract->id,
                'changed_by_user_id' => $user->id,
                'action' => 'verification_'.$verification->status,
                'reason' => trim($data['reason']),
                'before' => '{}',
                'after' => json_encode(['verification_status' => $verification->status], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        });

        return back()->with('status', 'Решение о подтверждении сохранено.');
    }
}
