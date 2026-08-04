<?php

namespace App\Modules\Telegram\Infrastructure\Jobs;

use App\Modules\Identity\Application\UseCases\StoreProfileAvatarHandler;
use App\Modules\Media\Application\Services\WebpImageNormalizer;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use App\Modules\Telegram\Infrastructure\Services\TelegramBotApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

final class SyncTelegramProfileAvatarJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly int $telegramAccountId,
    ) {}

    public function handle(StoreProfileAvatarHandler $storeProfileAvatar, TelegramBotApiClient $telegram): void
    {
        $account = TelegramAccount::query()
            ->with('user.profile.activeAvatar')
            ->find($this->telegramAccountId);

        $profile = $account?->user?->profile;
        $photoUrl = $account?->photo_url;

        if ($profile === null) {
            return;
        }

        $activeAvatar = $profile->activeAvatar;
        $reference = is_string($photoUrl) && str_starts_with($photoUrl, 'https://')
            ? hash('sha256', $photoUrl)
            : null;

        $telegramFilePath = null;
        if ($reference === null) {
            $photos = $telegram->call('getUserProfilePhotos', ['user_id' => $account->telegram_user_id, 'limit' => 1], 8);
            $sizes = data_get($photos, 'result.photos.0');
            $largest = is_array($sizes) ? end($sizes) : null;
            $fileId = is_array($largest) ? ($largest['file_id'] ?? null) : null;
            $fileUniqueId = is_array($largest) ? ($largest['file_unique_id'] ?? null) : null;
            if (! is_string($fileId) || ! is_string($fileUniqueId)) {
                return;
            }
            $file = $telegram->call('getFile', ['file_id' => $fileId], 8);
            $telegramFilePath = data_get($file, 'result.file_path');
            if (! is_string($telegramFilePath) || $telegramFilePath === '') {
                return;
            }
            $reference = hash('sha256', "telegram-profile:{$account->telegram_user_id}:{$fileUniqueId}");
        }

        $wasDeletedByUser = $profile->media()
            ->onlyTrashed()
            ->where('collection', 'avatar')
            ->where('source', 'telegram')
            ->where('source_reference', $reference)
            ->exists();

        if ($wasDeletedByUser || $activeAvatar?->source === 'upload' || $activeAvatar?->source_reference === $reference) {
            return;
        }

        if ($telegramFilePath !== null) {
            $contents = $telegram->downloadFile($telegramFilePath, 8);
            $contentLength = strlen($contents);
        } else {
            $response = Http::accept('image/jpeg,image/png,image/webp')
                ->connectTimeout(3)
                ->timeout(8)
                ->get($photoUrl)
                ->throw();
            $contentLength = (int) ($response->header('Content-Length') ?: 0);
            $contents = $response->body();
        }

        if ($contentLength > WebpImageNormalizer::MAX_INPUT_BYTES || strlen($contents) > WebpImageNormalizer::MAX_INPUT_BYTES) {
            return;
        }

        try {
            $storeProfileAvatar->handle($profile, $contents, 'telegram', $reference);
        } catch (InvalidArgumentException) {
            // Некорректный ответ CDN не должен повторно ставить задачу в очередь.
        }
    }
}
