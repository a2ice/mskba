<?php

namespace App\Modules\Vk\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Presentation\Http\Support\SafeAuthenticationRedirectResolver;
use App\Modules\Vk\Application\Services\VkOAuthFlowStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StartVkAuthenticationController extends Controller
{
    public function __invoke(
        Request $request,
        VkOAuthFlowStore $flows,
        SafeAuthenticationRedirectResolver $redirects,
    ): RedirectResponse {
        $appId = trim((string) config('vk.app_id'));

        if ($appId === '') {
            return redirect()->route('login')->with('error', 'Вход через VK ID сейчас недоступен.');
        }

        $mode = $request->user() === null ? 'login' : 'link';
        $fallback = $mode === 'link' ? route('account.contacts') : url('/');
        $redirectUrl = $redirects->resolve($request, $request->query('redirect_to'), $fallback);
        $flow = $flows->issue($request, $mode, $redirectUrl, $request->user()?->canonical()->id);
        $callbackUrl = trim((string) config('vk.redirect_uri')) ?: route('auth.vk.callback');
        $query = http_build_query([
            'client_id' => $appId,
            'response_type' => 'code',
            'redirect_uri' => $callbackUrl,
            'state' => $flow['state'],
            'code_challenge' => $flow['code_challenge'],
            'code_challenge_method' => 's256',
            'scope' => '',
        ], '', '&', PHP_QUERY_RFC3986);

        return redirect()->away(rtrim((string) config('vk.authorize_url'), '?').'?'.$query);
    }
}
