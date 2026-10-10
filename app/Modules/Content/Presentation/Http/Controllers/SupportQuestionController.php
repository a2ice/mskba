<?php

namespace App\Modules\Content\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Content\Domain\Models\SupportQuestion;
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
            'source_path' => ['required', 'string', 'max:255', 'regex:#^/(?!/)[^\\x00-\\x1f]*$#'],
        ]);
        $question = SupportQuestion::query()->create([
            'user_id' => $request->user()->id,
            'topic' => $data['topic'],
            'source_path' => $data['source_path'],
            'body' => $data['message'],
        ]);

        // Persist first: delivery failures must not lose a user's question.
        if (! config('support.deliver_email')) {
            return response()->json([
                'status' => 'saved',
                'message' => 'Тестовый вопрос сохранён в Dev. Отправка почты здесь отключена.',
                'question_id' => $question->id,
            ], 201);
        }

        $deliveryFailed = false;
        try {
            Mail::to(config('support.email'))->send(new SupportQuestionMail(
                $question,
                config('support.question_topics.'.$data['topic']),
            ));
            $question->forceFill(['emailed_at' => now()])->save();
        } catch (Throwable $e) {
            $deliveryFailed = true;
            Log::warning('Support question email delivery failed', ['question_id' => $question->id, 'exception' => $e::class]);
        }

        return response()->json([
            'status' => 'saved',
            'message' => $deliveryFailed
                ? 'Вопрос сохранён, но уведомление по почте временно не отправлено.'
                : 'Вопрос отправлен в поддержку. Спасибо!',
            'question_id' => $question->id,
        ], 201);
    }
}
