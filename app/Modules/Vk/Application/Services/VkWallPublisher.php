<?php

namespace App\Modules\Vk\Application\Services;

use App\Modules\Content\Domain\Models\ContentItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class VkWallPublisher
{
    public static function configured(): bool
    {
        return self::wallConfigured() && self::photoTokenAvailable();
    }

    public static function wallConfigured(): bool
    {
        return config('vk.wall.enabled') && config('app.env') === 'production'
            && (int) config('vk.wall.group_id') > 0 && filled(config('vk.wall.community_token'))
            && parse_url((string) config('app.url'), PHP_URL_HOST) === 'mskba.ru';
    }

    public static function photoTokenAvailable(): bool
    {
        $expires = config('vk.wall.user_token_expires_at');

        return filled(config('vk.wall.user_token')) && ($expires === null || (int) $expires > now()->timestamp + 120);
    }

    public function message(ContentItem $content): string
    {
        return trim($content->title)."\n\n".trim($content->short_description)."\n\n".$content->publicUrl();
    }

    public function prepareAttachment(ContentItem $content, int $groupId): string
    {
        $cover = $content->cover()->first();
        if ($cover === null) {
            throw new RuntimeException('Для публикации во ВКонтакте нужна обложка.');
        }

        $bytes = Storage::disk($cover->disk)->get($cover->path);
        $image = is_string($bytes) ? @imagecreatefromstring($bytes) : false;
        if ($image === false) {
            throw new RuntimeException('Не удалось прочитать обложку.');
        }
        ob_start();
        try {
            $ok = imagejpeg($image, null, 90);
            $jpeg = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
        if (! $ok || ! is_string($jpeg) || $jpeg === '') {
            throw new RuntimeException('Не удалось подготовить JPEG.');
        }

        $server = $this->call('photos.getWallUploadServer', ['group_id' => $groupId]);
        $url = (string) ($server['upload_url'] ?? '');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $allowed = collect(['vk.com', 'vk.ru', 'userapi.com', 'vkuserphoto.ru'])
            ->contains(fn (string $domain): bool => $host === $domain || str_ends_with($host, '.'.$domain));
        if (! $allowed || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PORT) !== null) {
            throw new RuntimeException('Недопустимый сервер загрузки ВКонтакте.');
        }

        try {
            $upload = Http::timeout(30)->withoutRedirecting()
                ->attach('photo', $jpeg, 'cover.jpg', ['Content-Type' => 'image/jpeg'])
                ->post($url);
            $data = $upload->json();
        } catch (Throwable) {
            throw new RuntimeException('Ошибка загрузки обложки ВКонтакте.');
        }
        if (! $upload->successful() || ! is_array($data) || empty($data['photo'])
            || empty($data['server']) || empty($data['hash'])) {
            throw new RuntimeException('ВКонтакте не принял обложку.');
        }

        $photos = $this->call('photos.saveWallPhoto', [
            'group_id' => $groupId, 'photo' => $data['photo'], 'server' => $data['server'], 'hash' => $data['hash'],
        ]);
        $photo = $photos[0] ?? [];
        if (! is_numeric($photo['owner_id'] ?? null) || (int) ($photo['id'] ?? 0) <= 0) {
            throw new RuntimeException('ВКонтакте не вернул ID фотографии.');
        }

        return 'photo'.(int) $photo['owner_id'].'_'.(int) $photo['id'];
    }

    public function publish(ContentItem $content, int $groupId, string $attachment): int
    {
        $data = ['owner_id' => -$groupId, 'from_group' => 1, 'message' => $this->message($content)];
        if (blank($attachment)) {
            throw new RuntimeException('Для публикации во ВКонтакте нужна обложка.');
        }
        $data['attachments'] = $attachment;

        $response = $this->call('wall.post', $data);
        $postId = (int) ($response['post_id'] ?? 0);
        if ($postId <= 0) {
            throw new RuntimeException('ВКонтакте не вернул ID записи.');
        }

        return $postId;
    }

    private function call(string $method, array $data): array
    {
        try {
            $response = Http::asForm()->timeout(20)->withoutRedirecting()
                ->post('https://api.vk.com/method/'.$method, [
                    ...$data,
                    'access_token' => $method === 'wall.post' ? config('vk.wall.community_token') : config('vk.wall.user_token'),
                    'v' => config('vk.wall.api_version'),
                ]);
            $payload = $response->json();
        } catch (Throwable) {
            throw new RuntimeException('Нет ответа ВКонтакте.');
        }
        if (is_numeric($payload['error']['error_code'] ?? null)) {
            throw new VkWallRejected('ВКонтакте отклонил запрос: код '.(int) $payload['error']['error_code'].'.');
        }
        if (! $response->successful() || ! is_array($payload['response'] ?? null)) {
            throw new RuntimeException('Некорректный ответ ВКонтакте.');
        }

        return $payload['response'];
    }
}
