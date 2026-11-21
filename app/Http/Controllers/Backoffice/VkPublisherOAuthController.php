<?php

namespace App\Http\Controllers\Backoffice;

use App\Filament\Backoffice\Pages\SocialIntegrationSettingsPage;
use App\Modules\Portal\Application\Services\SocialIntegrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class VkPublisherOAuthController
{
    private const SESSION = 'vk_publisher_oauth';

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
        $verifier = Str::random(64);
        $redirect = route('backoffice.vk-publisher.callback', [], true);
        $request->session()->put(self::SESSION, [
            'state' => $state, 'verifier' => $verifier, 'actor' => $request->user()->id,
            'app' => $appId, 'group' => $groupId, 'secret_hash' => hash('sha256', $secret),
            'until' => now()->timestamp + 600, 'redirect' => $redirect,
        ]);

        return redirect()->away('https://oauth.vk.ru/authorize?'.http_build_query([
            'client_id' => $appId, 'redirect_uri' => $redirect, 'display' => 'page',
            'scope' => 'photos', 'response_type' => 'code', 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256', 'v' => config('vk.wall.api_version', '5.199'),
        ], '', '&', PHP_QUERY_RFC3986))->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function callback(Request $request, SocialIntegrationSettings $settings): RedirectResponse
    {
        $this->authorizeAdmin();
        $flow = $request->session()->pull(self::SESSION);
        $state = $request->query('state');
        $code = $request->query('code');
        $secret = (string) config('vk.wall.mini_app_secret');
        if (! is_array($flow) || ! is_string($state) || ! hash_equals($flow['state'], $state)
            || $flow['until'] < now()->timestamp || $flow['actor'] !== $request->user()->id
            || $flow['app'] !== (int) config('vk.wall.mini_app_id')
            || $flow['group'] !== (int) config('vk.wall.group_id')
            || ! hash_equals($flow['secret_hash'], hash('sha256', $secret))
            || ! is_string($code) || $code === '' || strlen($code) > 4096) {
            return $this->finish('Авторизация не завершена. Запустите её заново из настроек.', false);
        }

        try {
            // Never log the request, credentials or raw OAuth response.
            $response = Http::asForm()->timeout(20)->withoutRedirecting()
                ->post('https://oauth.vk.ru/access_token', [
                    'client_id' => $flow['app'], 'client_secret' => $secret,
                    'redirect_uri' => $flow['redirect'], 'code' => $code,
                    'code_verifier' => $flow['verifier'], 'grant_type' => 'authorization_code',
                ]);
            $token = $response->json('access_token');
            $expires = $response->json('expires_in');
            if (! $response->successful() || ! is_string($token) || blank($token)
                || ! is_numeric($expires) || (int) $expires <= 120) {
                return $this->finish('ВК не выдал токен с подтверждённым сроком. Повторите авторизацию.', false);
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
