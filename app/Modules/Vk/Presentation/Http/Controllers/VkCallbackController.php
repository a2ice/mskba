<?php

namespace App\Modules\Vk\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Vk\Application\Services\VkOAuthFlowStore;
use App\Modules\Vk\Application\UseCases\CompleteVkAuthenticationHandler;
use App\Modules\Vk\Application\UseCases\LinkVkIdentityHandler;
use App\Modules\Vk\Application\UseCases\ResolveVkUserHandler;
use App\Modules\Vk\Infrastructure\Services\VkIdClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

final class VkCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        VkOAuthFlowStore $flows,
        VkIdClient $client,
        ResolveVkUserHandler $resolveUser,
        CompleteVkAuthenticationHandler $authenticate,
        LinkVkIdentityHandler $link,
    ): RedirectResponse {
        $failureRoute = 'login';

        try {
            if ($request->filled('error')) {
                $state = $request->validate(['state' => ['required', 'string', 'max:255']])['state'];
                $flows->consume($request, $state);
                throw new InvalidArgumentException('Вход через VK ID отменён или не подтверждён.');
            }

            $validated = $request->validate([
                'state' => ['required', 'string', 'max:255'],
                'code' => ['required', 'string', 'max:4096'],
                'device_id' => ['required', 'string', 'max:255'],
            ]);
            $flow = $flows->consume($request, $validated['state']);
            $failureRoute = $flow['mode'] === 'link' ? 'account.contacts' : 'login';
            $tokens = $client->exchangeCode(
                $validated['code'],
                $validated['device_id'],
                $flow['code_verifier'],
                $validated['state'],
            );
            $identity = $client->userInfo($tokens['access_token']);

            if (! hash_equals($tokens['user_id'], $identity->id)) {
                throw new InvalidArgumentException('VK ID вернул несовпадающие данные пользователя.');
            }

            if ($flow['mode'] === 'link') {
                $currentUser = $request->user()?->canonical();

                if ($currentUser === null || $flow['user_id'] !== (int) $currentUser->id) {
                    throw new InvalidArgumentException('Сессия привязки VK ID изменилась. Начните привязку заново.');
                }

                $result = $link->handle($currentUser, $identity);

                if ($result['status'] === 'duplicate') {
                    return redirect()->route('account.contacts')->with(
                        'warning',
                        'Этот VK ID уже связан с другим аккаунтом MSKBA. Запрос на проверку дубля создан для администратора.',
                    );
                }

                return redirect()->to($flow['redirect_url'])->with('success', 'VK ID подтверждён и привязан к аккаунту.');
            }

            $result = $resolveUser->handle($identity);
            $authenticate->handle($result['user']);

            return redirect()->to($flow['redirect_url'])->with('success', 'Вы вошли через VK ID.');
        } catch (InvalidArgumentException $exception) {
            return redirect()->route($failureRoute)->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route($failureRoute)->with('error', 'Не удалось завершить вход через VK ID. Попробуйте ещё раз.');
        }
    }
}
