<?php

namespace App\Modules\Content\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notification\Application\UseCases\CreateSupportTicketHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SupportQuestionController extends Controller
{
    public function __invoke(Request $request, CreateSupportTicketHandler $createSupportTicket): JsonResponse
    {
        $data = $request->validate([
            'topic' => ['required', 'string', Rule::in(array_keys(config('support.question_topics', [])))],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'source_path' => ['required', 'string', 'max:255', 'regex:#^/(?!/)[^\x00-\x1f]*$#'],
        ]);

        $topic = (string) config('support.question_topics.'.$data['topic']);
        $ticket = $createSupportTicket->handle(
            $request->user(),
            'FAQ — '.$topic,
            "Страница обращения: ".$data['source_path']."\n\n".$data['message'],
        );

        return response()->json([
            'status' => 'created',
            'message' => 'Обращение создано. Открываем переписку…',
            'redirect_url' => route('account.notifications.threads.support', $ticket, false),
        ], 201);
    }
}
