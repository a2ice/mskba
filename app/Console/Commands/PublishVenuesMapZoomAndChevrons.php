<?php

namespace App\Console\Commands;

use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderScriptAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-time, drift-guarded dev publication of map zoom and CSS chevrons.
 * HTML, venue data, other components and production are never touched.
 */
final class PublishVenuesMapZoomAndChevrons extends Command
{
    protected $signature = 'mskba:venues-map:zoom-chevrons {--check : Validate without writes} {--apply : Publish the two minimal changes}';

    protected $description = 'Publish one-step map zoom-out and centered filter chevrons after backup.';

    public function handle(PageBuilderPublisher $publisher): int
    {
        if (! app()->environment('staging')) {
            $this->error('Restricted to staging.');
            return self::FAILURE;
        }
        if ((bool) $this->option('check') === (bool) $this->option('apply')) {
            $this->error('Specify exactly one of --check and --apply.');
            return self::FAILURE;
        }

        try {
            $oldCss = $this->read('versions/venues-map-before-zoom.css');
            $oldJs = $this->read('versions/venues-map-before-zoom.js');
            $newCss = $this->read('venues-map.css');
            $newJs = $this->read('venues-map.js');

            if ($oldCss === $newCss || $oldJs === $newJs) {
                throw new RuntimeException('Unexpected identical source versions.');
            }
            // Lock and validate the *published* and *draft* CMS versions together,
            // so no editor changes can be accidentally overwritten.
            $state = DB::transaction(function () use ($publisher, $oldCss, $oldJs, $newCss, $newJs): string {
                $component = PageBuilderComponent::query()
                    ->where('slug', 'venues-map')->lockForUpdate()->firstOrFail();
                $script = PageBuilderScriptAsset::query()
                    ->where('entity_type', 'component')
                    ->where('entity_id', $component->getKey())
                    ->lockForUpdate()->firstOrFail();

                if ($component->published_at === null || ! $script->is_enabled) {
                    throw new RuntimeException('Expected a published and enabled component.');
                }

                $oldHash = hash('sha256', $oldJs);
                $newHash = hash('sha256', $newJs);
                $isNew = $component->published_css === $newCss
                    && $component->draft_css === $newCss
                    && $script->published_js === $newJs
                    && $script->draft_js === $newJs
                    && $script->published_hash === $newHash;
                if ($isNew) return 'ALREADY';

                if ($component->published_css !== $oldCss
                    || $component->draft_css !== $oldCss
                    || $script->published_js !== $oldJs
                    || $script->draft_js !== $oldJs
                    || $script->published_hash !== $oldHash) {
                    throw new RuntimeException('Map CSS/JS differs from the saved pre-zoom baseline.');
                }

                if ($this->option('check')) return 'READY';

                // Reuse the official CSS publication path to preserve previous_css
                // and the Filament CSS revision entry. This does not publish HTML.
                $publisher->saveAndPublishCss($component, $newCss);

                $script->previous_js = $script->published_js;
                $script->previous_hash = $script->published_hash;
                $script->draft_js = $newJs;
                $script->published_js = $newJs;
                $script->published_hash = $newHash;
                $script->published_at = now();
                $script->save();

                DB::table('page_builder_script_audit')->insert([
                    'entity_type' => 'component',
                    'entity_id' => $component->getKey(),
                    'action' => 'publish',
                    'user_id' => null,
                    'content_hash' => $newHash,
                    'created_at' => now(),
                ]);

                return 'PUBLISHED';
            });

            $this->line($state);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Map zoom/chevrons not published: '.$e->getMessage());
            return self::FAILURE;
        }
    }

    private function read(string $relative): string
    {
        $path = resource_path('page-builder/'.$relative);
        $value = is_file($path) ? file_get_contents($path) : false;
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('Missing component source: '.$relative);
        }
        return $value;
    }
}
