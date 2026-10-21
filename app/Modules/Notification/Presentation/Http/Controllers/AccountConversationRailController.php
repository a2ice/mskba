<?php

namespace App\Modules\Notification\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notification\Application\Services\AccountConversationRail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AccountConversationRailController extends Controller
{
    public function __invoke(Request $request, AccountConversationRail $rail): JsonResponse
    {
        $active = $request->query('active', '');
        abort_unless(is_string($active) && strlen($active) <= 64 &&
            ($active === '' || preg_match('/^(?:profile|direct|team|support|operator)-[0-9]+$|^system$/', $active)), 422);

        return response()->json([
            'html' => view('theme::pages.account.partials.conversation-rail-items', [
                'conversationCards' => $rail->items($request->user(), $request),
                'activeConversationId' => $active,
            ])->render(),
        ]);
    }
}
