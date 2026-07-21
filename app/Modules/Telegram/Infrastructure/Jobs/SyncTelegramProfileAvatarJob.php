<?php

namespace App\Modules\Telegram\Infrastructure\Jobs;

use App\Modules\Identity\Application\UseCases\StoreProfileAvatarHandler;
use App\Modules\Media\Application\Services\WebpImageNormalizer;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
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

    public function handle(StoreProfileAvatarHandler $storeProfileAvatar): void
    {
        $account = TelegramAccount::query()
            ->with('user.profile.activeAvatar')
            ->find($this->telegramAccountId);

        $profile = $account?->user?->profile;
        $photoUrl = $account?->photo_url;

        if ($profile === null || ! is_string($photoUrl) || ! str_starts_with($photoUrl, 'https://')) {
            return;
        }

        $activeAvatar = $profile->activeAvatar;
        $reference = hash('sha256', $photoUrl);

        $wasDeletedByUser = $profile->media()
            ->onlyTrashed()
            ->where('collection', 'avatar')
            ->where('source', 'telegram')
            ->where('source_reference', $reference)
            ->exists();

        if ($wasDeletedByUser || $activeAvatar?->source === 'upload' || $activeAvatar?->source_reference === $reference) {
            return;
        }

        $response = Http::accept('image/jpeg,image/png,image/webp')
            ->connectTimeout(3)
            ->timeout(8)
            ->get($photoUrl)
            ->throw();

        $contentLength = (int) ($response->header('Content-Length') ?: 0);
        $contents = $response->body();

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
