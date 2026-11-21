<?php

namespace App\Http\Controllers\Backoffice;

use App\Filament\Backoffice\Pages\SocialIntegrationSettingsPage;
use App\Modules\Portal\Application\Services\SocialIntegrationSettings;
use App\Modules\Telegram\Infrastructure\Services\TelegramBotApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class SocialIntegrationSettingsController
{
    public function save(Request $request, string $provider, SocialIntegrationSettings $settings): RedirectResponse
    {
        abort_unless(SocialIntegrationSettingsPage::canAccess(), 403);
        $rules = $provider === 'telegram' ? [
            'bot_token' => ['nullable', 'string', 'max:255', 'regex:/\A[0-9]+:[a-zA-Z0-9_-]+\z/'],
            'bot_username' => ['required', 'string', 'max:64', 'regex:/\A@?[a-zA-Z0-9_]+\z/'],
            'bot_domain' => ['nullable', 'string', 'max:255'],
            'main_chat_id' => ['required', 'string', 'regex:/\A-?[0-9]+\z/'],
            'community_url' => ['nullable', 'url:https', 'max:255', 'regex:~\Ahttps://t\.me/[a-zA-Z0-9_]+\z~'],
        ] : [
            'community_url' => ['required', 'url:https', 'max:255', 'regex:~\Ahttps://vk\.(?:ru|com)/[a-zA-Z0-9_.-]+\z~'],
            'group_id' => ['nullable', 'integer', 'min:1'],
            'community_token' => ['nullable', 'string', 'max:2048'],
            'user_token' => ['nullable', 'string', 'max:2048'],
            'enabled' => ['required', 'boolean'],
        ];
        $validator = Validator::make($request->all(), [...$rules, 'password' => ['required', 'string', 'max:255']]);
        if ($validator->fails()) {
            // Never flash request input (tokens and password).
            return $this->page()->withErrors($validator);
        }
        $data = $validator->validated();
        $actor = $request->user();
        $rateKey = 'social-settings:'.$actor->id;
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            return $this->page()->withErrors(['social' => 'Слишком много попыток. Повторите позже.']);
        }
        RateLimiter::hit($rateKey, 60);
        if (! Hash::check($data['password'], $actor->getAuthPassword())) {
            return $this->page()->withErrors(['social' => 'Неверный пароль.']);
        }
        RateLimiter::clear($rateKey);
        unset($data['password']);

        if ($provider === 'telegram') {
            $data['bot_username'] = ltrim($data['bot_username'], '@');
            if (filled($data['bot_token'] ?? null)) {
                $previous = config('telegram.bot_token');
                try {
                    config(['telegram.bot_token' => $data['bot_token']]);
                    $bot = app(TelegramBotApiClient::class)->call('getMe');
                    if (strcasecmp((string) data_get($bot, 'result.username'), $data['bot_username']) !== 0) {
                        return $this->page()->withErrors(['social' => 'Имя бота не соответствует токену.']);
                    }
                } catch (Throwable) {
                    return $this->page()->withErrors(['social' => 'Telegram не подтвердил токен. Настройки не изменены.']);
                } finally {
                    config(['telegram.bot_token' => $previous]);
                }
            }
        } else {
            $data['enabled'] = (bool) $data['enabled'];
            $data['group_id'] = (int) ($data['group_id'] ?? 0);
            if ($data['enabled'] && (config('app.env') !== 'production'
                || parse_url((string) config('app.url'), PHP_URL_HOST) !== 'mskba.ru'
                || $data['group_id'] <= 0
                || blank($data['user_token'] ?? config('vk.wall.user_token')) && blank(config('vk.wall.user_token')))) {
                return $this->page()->withErrors(['social' => 'Для публикации нужны production, ID сообщества и пользовательский ключ VK API.']);
            }
        }
        $settings->save($provider, $data, $actor->id);

        return $this->page()->with('social_status', 'Настройки сохранены.');
    }

    public function check(string $provider, TelegramBotApiClient $telegram): RedirectResponse
    {
        abort_unless(SocialIntegrationSettingsPage::canAccess(), 403);
        try {
            if ($provider === 'telegram') {
                $bot = $telegram->call('getMe');
                $webhook = $telegram->call('getWebhookInfo');
                $expected = rtrim((string) config('app.url'), '/').'/api/v1/telegram/webhook';
                $url = (string) data_get($webhook, 'result.url');
                $state = $url !== '' ? 'настроен' : 'не задан';
                // Do not surface arbitrary provider URLs or payloads.
                return $this->page()->with('social_status', 'Telegram: бот @'.preg_replace('/[^a-zA-Z0-9_]/', '', (string) data_get($bot, 'result.username')).'; webhook '.$state.'.');
            }
            $token = config('vk.wall.community_token') ?: config('vk.wall.user_token');
            if (blank($token)) {
                return $this->page()->withErrors(['social' => 'Сначала сохраните ключ ВКонтакте.']);
            }
            $group = basename((string) config('services.social.vk_url'));
            $response = Http::asForm()->withoutRedirecting()->timeout(15)->post('https://api.vk.com/method/groups.getById', [
                'group_id' => $group,
                'access_token' => $token,
                'v' => config('vk.wall.api_version', '5.199'),
            ]);
            $payload = $response->json();
            $id = (int) (data_get($payload, 'response.groups.0.id') ?? data_get($payload, 'response.0.id'));
            if (! $response->successful() || $id <= 0) {
                $code = (int) data_get($payload, 'error.error_code');
                return $this->page()->withErrors(['social' => 'ВКонтакте не подтвердил сообщество. Код: '.$code.'.']);
            }
            $message = 'ВКонтакте: сообщество ID '.$id.'.';
            if ((int) config('vk.wall.group_id') > 0 && (int) config('vk.wall.group_id') !== $id) {
                return $this->page()->withErrors(['social' => $message.' Сохранённый ID не совпадает.']);
            }
            // Request an upload endpoint only, never upload or publish during checks.
            $photoToken = config('vk.wall.user_token') ?: $token;
            $photo = Http::asForm()->withoutRedirecting()->timeout(15)->post('https://api.vk.com/method/photos.getWallUploadServer', [
                'group_id' => $id, 'access_token' => $photoToken, 'v' => config('vk.wall.api_version', '5.199'),
            ]);
            $photoData = $photo->json();
            $message .= filled(data_get($photoData, 'response.upload_url'))
                ? ' Сервер загрузки обложек доступен.'
                : ' Загрузка обложек не подтверждена; код '.(int) data_get($photoData, 'error.error_code').'.';
            return $this->page()->with('social_status', $message.' Запись на стене не создавалась.');
        } catch (Throwable) {
            return $this->page()->withErrors(['social' => 'Не удалось проверить подключение.']);
        }
    }

    private function page(): RedirectResponse
    {
        return redirect('/backoffice/social-integrations');
    }
}
