<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Venue\Domain\Enums\VenueOwnershipDocumentTypeEnum;
use App\Modules\Venue\Domain\Models\VenueOwnershipDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AdminVenueOwnershipDocumentController extends Controller
{
    public function update(Request $request, VenueOwnershipDocument $document): RedirectResponse
    {
        $user = $request->user()?->canonical();
        abort_unless(
            $user !== null && $user->isConfirmed() && $user->system_role->atLeast(UserSystemRoleEnum::ADMIN),
            403,
        );

        $validated = $request->validate([
            'type' => ['required', Rule::enum(VenueOwnershipDocumentTypeEnum::class)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $document->forceFill([
            'type' => VenueOwnershipDocumentTypeEnum::from($validated['type']),
            'note' => filled($validated['note'] ?? null) ? trim((string) $validated['note']) : null,
        ])->save();

        return back()->with('success', 'Тип и комментарий документа обновлены.');
    }
}
