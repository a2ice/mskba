<?php

namespace App\Console\Commands;

use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Content\Domain\Models\PageBuilderTemplate;
use App\Modules\Media\Application\Services\SiteMediaManager;
use App\Modules\Media\Domain\Models\SiteMediaAsset;
use App\Modules\Media\Domain\Models\SiteMediaFolder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Safe, idempotent import of images already referenced in published/draft
 * Page Builder data. Never edits published snapshots or deletes Git assets.
 */
final class ImportHeroSiteMediaCommand extends Command
{
    protected $signature = 'site-media:import-hero {--apply : Import sources (without this flag only audit)} {--skip-missing : Report missing sources without blocking rollout}';
    protected $description = 'Inventory and import legacy Hero Slider/Page Builder images into the media library';

    public function handle(SiteMediaManager $manager): int
    {
        $references = [];
        $walk = static function (mixed $value) use (&$walk, &$references): void {
            if (is_array($value)) {
                foreach ($value as $child) {
                    $walk($child);
                }
            } elseif (is_string($value)) {
                preg_match_all('~(?:/images/[A-Za-z0-9_./-]+\.(?:png|jpe?g|webp)|page-builder/hero/[A-Za-z0-9_-]+\.(?:png|jpe?g|webp|avif))~i', $value, $found);
                foreach ($found[0] as $path) {
                    $references[$path] = true;
                }
            }
        };
        $slider = PageBuilderComponent::query()->where('slug', 'hero-slider')->first();
        if ($slider) {
            $walk($slider->draft_slides);
            $walk($slider->published_slides);
        }
        foreach ([PageBuilderPage::class, PageBuilderTemplate::class, PageBuilderComponent::class] as $model) {
            foreach ($model::query()->get() as $record) {
                foreach (['draft_html', 'published_html', 'draft_css', 'published_css', 'draft_js', 'published_js'] as $name) {
                    $walk($record->getAttribute($name));
                }
            }
        }
        ksort($references);

        $folder = null;
        if ($this->option('apply')) {
            $folder = SiteMediaFolder::query()->firstOrCreate(['slug' => 'hero-slider'], ['name' => 'Hero Slider']);
        }
        $imported = 0;
        $missing = 0;
        foreach (array_keys($references) as $source) {
            if (SiteMediaAsset::query()->where('legacy_path', $source)->exists()) {
                $this->line('EXISTS   '.$source);
                continue;
            }
            if (str_starts_with($source, '/images/')) {
                $absolute = public_path(ltrim($source, '/'));
                $contents = is_file($absolute) ? file_get_contents($absolute) : false;
            } else {
                $contents = Storage::disk('public')->exists($source)
                    ? Storage::disk('public')->get($source) : false;
            }
            if (! is_string($contents) || $contents === '') {
                $missing++;
                $this->warn('MISSING  '.$source);
                continue;
            }
            if (! $this->option('apply')) {
                $this->line('READY   '.$source.' ('.strlen($contents).' bytes)');
                continue;
            }
            try {
                $manager->importBytes($contents, Str::headline(pathinfo($source, PATHINFO_FILENAME)),
                    basename($source), $folder->id, false, $source);
                $imported++;
                $this->info('IMPORTED '.$source);
            } catch (Throwable $e) {
                $missing++;
                $this->error('FAILED  '.$source.': '.$e->getMessage());
            }
        }
        $this->info('Referenced: '.count($references).'; imported: '.$imported.'; missing/errors: '.$missing);
        return $missing > 0 && ! $this->option('skip-missing') ? self::FAILURE : self::SUCCESS;
    }
}
