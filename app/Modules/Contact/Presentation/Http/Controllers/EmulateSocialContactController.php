<?php

namespace App\Modules\Contact\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contact\Application\UseCases\EmulateSocialContactHandler;
use App\Modules\Identity\Application\Services\AccountCheckForPresentationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class EmulateSocialContactController extends Controller
{
    public static function enabled(): bool
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return (bool) config('contact_emulation.enabled')
            && app()->environment(['local', 'staging', 'testing'])
            && in_array($host, ['localhost', '127.0.0.1', 'dev.mskba.ru'], true);
    }

    public function __invoke(
        Request $request,
        AccountCheckForPresentationService $accountCheck,
        EmulateSocialContactHandler $emulate,
    ): JsonResponse {
        // A mismatched Host header must not activate this staging-only route.
        abort_unless(
            self::enabled()
                && $request->getHost() === parse_url((string) config('app.url'), PHP_URL_HOST),
            404,
        );

        $data = Validator::make($request->all(), [
            'provider' => ['required', 'in:telegram,vk'],
            'outcome' => ['required', 'in:success,denied,cancelled,unavailable,timeout,conflict'],
        ])->validate();

        $failures = [
            'denied' => [403, 'Провайдер отклонил авторизацию. Попробуй ещё раз.'],
            'cancelled' => [422, 'Авторизация отменена. Контакт не добавлен.'],
            'unavailable' => [502, 'Сервис авторизации временно недоступен.'],
            'timeout' => [504, 'Истекло время ожидания ответа провайдера.'],
            'conflict' => [409, 'Эта учётная запись уже привязана к другому пользователю.'],
        ];
        if (isset($failures[$data['outcome']])) {
            [$status, $message] = $failures[$data['outcome']];

            return response()->json(['message' => $message, 'emulated' => true], $status);
        }

        $user = $accountCheck->handle($request->user());
        $contact = $emulate->handle($user, $data['provider']);

        return response()->json([
            'message' => 'Тестовая авторизация успешна. Контакт добавлен и подтверждён (эмуляция).',
            'emulated' => true,
            'contact' => [
                'id' => $contact->id,
                'type' => $contact->type->value,
                'value' => $contact->displayValue(),
                'verified' => true,
                'primary' => $contact->is_primary,
            ],
        ], 201);
    }
}
