<?php

namespace App\Modules\Vk\Infrastructure\Jobs;

use App\Modules\Identity\Application\UseCases\StoreProfileAvatarHandler;
use App\Modules\Media\Application\Services\WebpImageNormalizer;
use App\Modules\Vk\Domain\Models\VkAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

final class SyncVkProfileAvatarJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $vkAccountId) {}

    public function handle(StoreProfileAvatarHandler $storeProfileAvatar): void
    {
        $account = VkAccount::query()->with('user')->find($this->vkAccountId);
        $profile = $account?->user?->canonical()->profile()->with('activeAvatar')->first();
        $avatarUrl = $account?->avatar_url;

        if ($profile === null || ! is_string($avatarUrl) || ! str_starts_with($avatarUrl, 'https://')) {
            return;
        }

        $activeAvatar = $profile->activeAvatar;
        if ($activeAvatar?->source === 'upload') {
            return;
        }

        $reference = hash('sha256', $avatarUrl);
        $wasDeletedByUser = $profile->media()
            ->onlyTrashed()
            ->where('collection', 'avatar')
            ->where('source', 'vk')
            ->where('source_reference', $reference)
            ->exists();

        if ($wasDeletedByUser || $activeAvatar?->source_reference === $reference) {
            return;
        }

        $response = Http::accept('image/jpeg,image/png,image/webp')
            ->connectTimeout(3)
            ->timeout(8)
            ->get($avatarUrl)
            ->throw();
        $contentLength = (int) ($response->header('Content-Length') ?: 0);
        $contents = $response->body();

        if ($contentLength > WebpImageNormalizer::MAX_INPUT_BYTES || strlen($contents) > WebpImageNormalizer::MAX_INPUT_BYTES) {
            return;
        }

        try {
            $storeProfileAvatar->handle($profile, $contents, 'vk', $reference);
        } catch (InvalidArgumentException) {
            // Некорректный ответ CDN не должен повторно ставить задачу в очередь.
        }
    }
}
