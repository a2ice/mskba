<?php

namespace App\Modules\Content\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Content\Infrastructure\Mail\SupportQuestionMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

final class SupportQuestionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'topic' => ['required', 'string', Rule::in(array_keys(config('support.question_topics', [])))],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'source_path' => ['required', 'string', 'max:255', 'regex:#^/(?!/)[^\x00-\x1f]*$#'],
        ]);

        // Do not acknowledge success when the configured driver only logs or
        // discards email. The UI can suggest the configured mailto fallback.
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            return response()->json([
                'message' => 'Отправка писем на этом сервере пока не настроена. Напишите на почту поддержки.',
            ], 503);
        }

        try {
            Mail::to(config('support.email'))->send(new SupportQuestionMail(
                userId: (int) $request->user()->id,
                topicLabel: (string) config('support.question_topics.'.$data['topic']),
                sourcePath: $data['source_path'],
                questionBody: $data['message'],
            ));
        } catch (Throwable $exception) {
            // Never log the submitted message or transport credentials.
            Log::warning('Support question mail submission failed', [
                'user_id' => (int) $request->user()->id,
                'exception' => $exception::class,
            ]);

            return response()->json([
                'message' => 'Не удалось отправить вопрос. Попробуйте позже или напишите на почту поддержки.',
            ], 503);
        }

        return response()->json([
            'status' => 'sent',
            'message' => 'Вопрос отправлен в поддержку. Спасибо!',
        ], 200);
    }
}
