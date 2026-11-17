<?php

namespace App\Modules\Media\Infrastructure\Jobs;

use App\Modules\Media\Application\Services\SiteMediaLqip;
use App\Modules\Media\Domain\Models\SiteMediaAsset;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Each batch is deliberately small, bounded and retry-safe. */
final class GenerateSiteMediaLqipChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 90;

    /** @param list<int> $assetIds */
    public function __construct(public array $assetIds, public string $batchId) {}

    public function handle(SiteMediaLqip $generator): void
    {
        foreach ($this->assetIds as $id) {
            $asset = SiteMediaAsset::query()->with('currentMedia')->find($id);
            if ($asset && $asset->currentMedia) {
                try {
                    if ($generator->exists($asset->currentMedia)) {
                        Cache::increment('site-media:lqip:'.$this->batchId.':skipped');
                    } elseif ($generator->generate($asset->currentMedia)) {
                        Cache::increment('site-media:lqip:'.$this->batchId.':created');
                    } else {
                        Cache::increment('site-media:lqip:'.$this->batchId.':skipped');
                    }
                } catch (Throwable $exception) {
                    Cache::increment('site-media:lqip:'.$this->batchId.':errors');
                    report($exception);
                }
            } else {
                Cache::increment('site-media:lqip:'.$this->batchId.':skipped');
            }
            Cache::increment('site-media:lqip:'.$this->batchId.':processed');
        }
    }
}
