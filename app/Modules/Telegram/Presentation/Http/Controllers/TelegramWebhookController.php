<?php

namespace App\Modules\Telegram\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Telegram\Infrastructure\Jobs\ProcessTelegramCallbackJob;
use App\Modules\Telegram\Infrastructure\Jobs\ProcessTelegramMessageJob;
use App\Modules\Telegram\Infrastructure\Jobs\ProcessTelegramReactionCountJob;
use App\Modules\Telegram\Infrastructure\Jobs\ProcessTelegramReactionJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $expectedSecret = (string) config('telegram.webhook_secret');
        $providedSecret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');

        abort_if($expectedSecret === '' || ! hash_equals($expectedSecret, $providedSecret), 403);

        $callback = $request->input('callback_query');

        if (is_array($callback)) {
            ProcessTelegramCallbackJob::dispatch($callback);
        }

        $message = $request->input('message');

        if (is_array($message)) {
            ProcessTelegramMessageJob::dispatch($message);
        }

        $reaction = $request->input('message_reaction');
        if (is_array($reaction)) {
            $updateId = $request->input('update_id');
            ProcessTelegramReactionJob::dispatch(
                $reaction,
                is_numeric($updateId) ? (int) $updateId : null,
            );
        }

        $reactionCount = $request->input('message_reaction_count');
        if (is_array($reactionCount)) {
            $updateId = $request->input('update_id');
            ProcessTelegramReactionCountJob::dispatch(
                $reactionCount,
                is_numeric($updateId) ? (int) $updateId : null,
            );
        }

        return response()->json(['ok' => true]);
    }
}
