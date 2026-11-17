<?php

namespace App\Console\Commands;

use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderScriptAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Small staged fix: leave Filament HTML/CSS and other components untouched,
 * publish JS only if both stored drafts match the exact approved V2 baseline.
 */
final class PublishVenuesMapClickOnly extends Command
{
    protected $signature = 'mskba:venues-map:click-only {--check : Check current component without writes} {--apply : Publish the JS fix once}';

    protected $description = 'Remove unwanted map popup on page load without overwriting editor changes.';

    public function handle(): int
    {
        if (! app()->environment('staging')) {
            $this->error('Restricted to staging.');
            return self::FAILURE;
        }

        if ((bool) $this->option('check') === (bool) $this->option('apply')) {
            $this->error('Choose exactly one of --check or --apply.');
            return self::FAILURE;
        }

        $previous = $this->read('versions/venues-map-v2.js');
        $fixed = $this->read('venues-map.js');

        try {
            if (! str_contains($previous, 'openCard(items[0].venue,items[0].marker)')
                || str_contains($fixed, 'openCard(items[0].venue,items[0].marker)')
                || $previous === $fixed) {
                throw new RuntimeException('Invalid JS patch baseline.');
            }

            $work = function () use ($previous, $fixed): string {
                $component = PageBuilderComponent::query()->where('slug', 'venues-map')
                    ->lockForUpdate()->firstOrFail();
                if ($component->published_at === null) {
                    throw new RuntimeException('Component is not published.');
                }
                $asset = PageBuilderScriptAsset::query()
                    ->where('entity_type', 'component')->where('entity_id', $component->getKey())
                    ->lockForUpdate()->firstOrFail();

                $oldHash = hash('sha256', $previous);
                $newHash = hash('sha256', $fixed);
                if ($asset->published_hash === $newHash
                    && $asset->draft_js === $fixed && $asset->published_js === $fixed
                    && $asset->is_enabled) {
                    return 'ALREADY';
                }

                if ($asset->published_hash !== $oldHash || $asset->draft_js !== $previous
                    || $asset->published_js !== $previous || ! $asset->is_enabled) {
                    throw new RuntimeException('Published/draft JS differs from V2; refusing overwrite.');
                }

                if ($this->option('check')) {
                    return 'READY';
                }

                $asset->previous_js = $asset->published_js;
                $asset->previous_hash = $asset->published_hash;
                $asset->draft_js = $fixed;
                $asset->published_js = $fixed;
                $asset->published_hash = $newHash;
                $asset->published_at = now();
                $asset->is_enabled = true;
                $asset->save();

                DB::table('page_builder_script_audit')->insert([
                    'entity_type' => 'component',
                    'entity_id' => $component->getKey(),
                    'action' => 'publish',
                    'user_id' => null,
                    'content_hash' => $newHash,
                    'created_at' => now(),
                ]);

                return 'PUBLISHED';
            };

            $result = DB::transaction($work);
            $this->line($result);
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Click-only patch not applied: '.$exception->getMessage());
            return self::FAILURE;
        }
    }

    private function read(string $file): string
    {
        $path = resource_path('page-builder/'.$file);
        $source = is_file($path) ? file_get_contents($path) : false;
        if (! is_string($source) || trim($source) === '') {
            throw new RuntimeException('Missing JS source: '.$file);
        }
        return $source;
    }
}
