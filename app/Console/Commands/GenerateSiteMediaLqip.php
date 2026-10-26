<?php

namespace App\Console\Commands;

use App\Modules\Media\Application\Services\SiteMediaLqip;
use App\Modules\Media\Domain\Models\SiteMediaAsset;
use Illuminate\Console\Command;
use Throwable;

/**
 * Idempotent server-side backfill for the existing Site Media library.
 * Dry-run by default. Never mutates originals, Media versions or CMS content.
 */
final class GenerateSiteMediaLqip extends Command
{
    protected $signature = 'site-media:lqip
        {--apply : Generate previews (dry-run otherwise)}
        {--force : Rebuild even existing previews}
        {--limit=0 : Maximum number of assets to process, zero for all}';

    protected $description = 'Build missing low-quality WebP derivatives for Site Media Library.';

    public function handle(SiteMediaLqip $generator): int
    {
        $force = (bool) $this->option('force');
        $apply = (bool) $this->option('apply');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 0) {
            $this->error('Invalid nonnegative --limit.');

            return self::FAILURE;
        }

        $stats = ['checked' => 0, 'needed' => 0, 'created' => 0, 'failed' => 0];
        SiteMediaAsset::query()->whereNotNull('current_media_id')->with('currentMedia')->orderBy('id')
            ->chunkById(50, function ($assets) use ($generator, $force, $apply, $limit, &$stats): bool {
                foreach ($assets as $asset) {
                    if ($limit > 0 && $stats['checked'] >= $limit) {
                        return false;
                    }
                    $stats['checked']++;
                    $media = $asset->currentMedia;
                    if (! $media || (! $force && $generator->exists($media))) {
                        continue;
                    }
                    $stats['needed']++;
                    if (! $apply) {
                        continue;
                    }
                    try {
                        if ($generator->generate($media, $force)) {
                            $stats['created']++;
                        } else {
                            $stats['failed']++;
                        }
                    } catch (Throwable $exception) {
                        $stats['failed']++;
                        $this->warn('Asset '.$asset->id.': '.$exception->getMessage());
                    }
                }

                return true;
            });

        $this->info(($apply ? 'APPLY' : 'DRY RUN').': '.json_encode($stats, JSON_UNESCAPED_UNICODE));

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
