<?php

namespace App\Http\Controllers\Backoffice;

use App\Filament\Backoffice\Pages\SocialIntegrationSettingsPage;
use App\Modules\Portal\Application\Services\SocialIntegrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class VkPublisherOAuthController
{
    private const REDIRECT = 'https://oauth.vk.com/blank.html';

    public function start(Request $request): RedirectResponse
    {
        $this->authorizeAdmin();
        $appId = (int) config('vk.wall.mini_app_id');
        $groupId = (int) config('vk.wall.group_id');
        $secret = (string) config('vk.wall.mini_app_secret');
        if ($appId <= 0 || $groupId <= 0 || blank($secret) || blank(config('vk.wall.community_token'))) {
            return $this->finish('Сначала сохраните ID сообщества, ключ сообщества и параметры Mini App.', false);
        }

        $state = Str::random(64);
        $redirect = self::REDIRECT;
        $key = $this->flowKey($request);
        $stored = Cache::lock($key.':lock', 10)->get(fn () => Cache::put($key, [
            'state_hash' => hash('sha256', $state), 'actor' => (int) $request->user()->id,
            'app' => $appId, 'group' => $groupId, 'secret_hash' => hash('sha256', $secret),
            'until' => now()->timestamp + 600, 'redirect' => $redirect,
        ], 600));
        if (! $stored) {
            return $this->finish('Не удалось начать авторизацию. Повторите попытку.', false);
        }

        return redirect()->away('https://oauth.vk.com/authorize?'.http_build_query([
            'client_id' => $appId, 'redirect_uri' => $redirect, 'display' => 'page',
            'scope' => 'photos', 'response_type' => 'code', 'state' => $state,
            // Legacy VK server-side OAuth rejects PKCE; keep client secret and one-time session state.
            'v' => config('vk.wall.api_version', '5.199'),
        ], '', '&', PHP_QUERY_RFC3986))->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function complete(Request $request, SocialIntegrationSettings $settings): RedirectResponse
    {
        $this->authorizeAdmin();
        // Only accept the one-time authorization response from the official VK blank page.
        // No arbitrary redirect, implicit bearer token, or query-string submission.
        $returnUrl = $request->input('authorization_response');
        $params = [];
        $validUrl = false;
        if (is_string($returnUrl) && strlen($returnUrl) <= 8192) {
            $url = parse_url(trim($returnUrl));
            if (is_array($url) && ($url['scheme'] ?? null) === 'https'
                && in_array($url['host'] ?? null, ['oauth.vk.com', 'oauth.vk.ru'], true)
                && ($url['path'] ?? null) === '/blank.html'
                && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['port'])
                && ! (isset($url['query']) && isset($url['fragment']))) {
                // VK returns code and state in the fragment on the live blank page.
                // Accept either representation, never merge ambiguous parameter sets.
                $validUrl = true;
                parse_str($url['fragment'] ?? $url['query'] ?? '', $params);
            }
        }
        if (! $validUrl) {
            return $this->finish('OAuth VK: неверный формат адреса ответа (VK-A01).', false);
        }
        $state = $params['state'] ?? null;
        $code = $params['code'] ?? null;
        if (isset($params['access_token']) || isset($params['refresh_token'])) {
            return $this->finish('Нужен одноразовый код, а не токен. Запустите авторизацию заново.', false);
        }
        if (! is_string($state) || $state === '') {
            return $this->finish('OAuth VK: отсутствует параметр state (VK-A02).', false);
        }
        // OAuth authorization codes are opaque; accept printable non-space ASCII,
        // pass them unchanged as form data, never to a URL or log.
        if (! is_string($code) || ! preg_match('/\A[\x21-\x7e]{1,4096}\z/D', $code)) {
            return $this->finish('OAuth VK: отсутствует или некорректен код авторизации (VK-A03).', false);
        }
        $secret = (string) config('vk.wall.mini_app_secret');
        $key = $this->flowKey($request);
        $result = Cache::lock($key.':lock', 10)->get(function () use ($key, $state, $request, $secret): array {
            $flow = Cache::get($key);
            if (! is_array($flow) || $flow['until'] < now()->timestamp) {
                return ['error' => 'Сессия авторизации не найдена, истекла или уже использована (VK-A04).'];
            }
            if (! hash_equals($flow['state_hash'], hash('sha256', $state))) {
                return ['error' => 'Параметр state не соответствует начатой авторизации (VK-A05).'];
            }
            if ($flow['actor'] !== (int) $request->user()->id
                || $flow['app'] !== (int) config('vk.wall.mini_app_id')
                || $flow['group'] !== (int) config('vk.wall.group_id')
                || ! hash_equals($flow['secret_hash'], hash('sha256', $secret))) {
                return ['error' => 'Изменились параметры приложения или администратора (VK-A06).'];
            }
            Cache::forget($key);

            return ['flow' => $flow];
        });
        if (! is_array($result) || isset($result['error'])) {
            return $this->finish('OAuth VK: '.($result['error'] ?? 'Авторизация уже обрабатывается (VK-A07).'), false);
        }
        $flow = $result['flow'];

        try {
            // Never log the request, credentials or raw OAuth response.
            $response = Http::asForm()->timeout(20)->withoutRedirecting()
                ->post('https://oauth.vk.com/access_token', [
                    'client_id' => $flow['app'], 'client_secret' => $secret,
                    'redirect_uri' => $flow['redirect'], 'code' => $code,
                ]);
            $token = $response->json('access_token');
            $expires = $response->json('expires_in');
            if (! $response->successful() || ! is_string($token) || blank($token)
                || ! is_numeric($expires) || (int) $expires <= 120) {
                $error = $response->json('error');
                $reason = match ($error) {
                    'invalid_grant' => 'Код истёк или уже использован.',
                    'invalid_client' => 'ВК не принял параметры приложения.',
                    'invalid_request' => 'ВК отклонил параметры обмена кода.',
                    default => 'ВК не выдал токен с подтверждённым сроком.',
                };
                return $this->finish($reason.' Повторите авторизацию.', false);
            }

            $probe = Http::asForm()->timeout(15)->withoutRedirecting()
                ->post('https://api.vk.com/method/photos.getWallUploadServer', [
                    'group_id' => $flow['group'], 'access_token' => $token,
                    'v' => config('vk.wall.api_version', '5.199'),
                ]);
            if (! $probe->successful() || blank($probe->json('response.upload_url'))) {
                return $this->finish('ВК не подтвердил загрузку фотографий. Код: '.(int) $probe->json('error.error_code').'.', false);
            }
        } catch (Throwable) {
            return $this->finish('Не удалось завершить подключение ВКонтакте.', false);
        }

        $settings->save('vk', [
            'user_token' => $token,
            'user_token_expires_at' => now()->timestamp + (int) $expires,
        ], $request->user()->id);

        return $this->finish('Авторизация для загрузки фотографий успешна. Проверьте подключение и включите публикацию.');
    }

    private function flowKey(Request $request): string
    {
        return 'vk-publisher-oauth:'.hash('sha256', $request->session()->getId().'|'.(int) $request->user()->id);
    }

    private function authorizeAdmin(): void
    {
        abort_unless(SocialIntegrationSettingsPage::canAccess()
            && config('app.env') === 'production'
            && parse_url((string) config('app.url'), PHP_URL_HOST) === 'mskba.ru', 403);
    }

    private function finish(string $message, bool $success = true): RedirectResponse
    {
        $response = redirect('/backoffice/social-integrations')
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');

        return $success ? $response->with('social_status', $message) : $response->withErrors(['social' => $message]);
    }
}
