<?php

namespace App\Console\Commands;

use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderScriptAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-time guarded import into the existing versioned Filament component.
 *
 * Intended solely for the MSKBA staging site after a verified pg_dump.
 * The marker prevents future deploys from overwriting editorial edits.
 */
final class PublishHomepageVenuesMap extends Command
{
    protected $signature = 'mskba:venues-map:publish {--apply : Publish the first version into dev database}';

    protected $description = 'Publish the initial homepage map HTML/CSS/JS once; never overwrite later Filament changes.';

    private const MARKER = '[mskba-venues-map:v1-installed]';

    public function handle(PageBuilderPublisher $publisher): int
    {
        if (! app()->environment('staging')) {
            $this->error('Only the staging environment can run this installer.');
            return self::FAILURE;
        }

        $component = PageBuilderComponent::query()->where('slug', 'venues-map')->first();
        if ($component === null) {
            $this->error('The existing Filament venues-map component was not found; refusing to create or change another component.');
            return self::FAILURE;
        }

        if (str_contains((string) $component->description, self::MARKER)) {
            $this->info('venues-map v1 already installed. Preserve all later Filament edits; no changes.');
            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->warn('Dry run. Existing venues-map component found; use --apply only after verified database backup.');
            return self::SUCCESS;
        }

        $html = $this->source('venues-map.html');
        $css = $this->source('venues-map.css');
        $js = $this->source('venues-map.js');

        DB::transaction(function () use ($publisher, $component, $html, $css, $js): void {
            $item = PageBuilderComponent::query()->whereKey($component->getKey())->lockForUpdate()->firstOrFail();
            if (str_contains((string) $item->description, self::MARKER)) {
                return;
            }

            $description = trim((string) $item->description.' '.self::MARKER);
            if (mb_strlen($description) > 500) {
                throw new RuntimeException('Component description is too long for installer marker.');
            }
            $item->description = $description;
            $item->draft_html = $html;
            $item->draft_css = $css;
            $item->save();
            $publisher->publish($item);

            $script = PageBuilderScriptAsset::query()->firstOrCreate(
                ['entity_type' => 'component', 'entity_id' => $item->getKey()],
                ['is_enabled' => false],
            );
            $hash = hash('sha256', $js);
            if ($hash !== $script->published_hash) {
                $script->previous_js = $script->published_js;
                $script->previous_hash = $script->published_hash;
                $script->draft_js = $js;
                $script->published_js = $js;
                $script->published_hash = $hash;
                $script->published_at = now();
                $script->is_enabled = true;
                $script->save();
                DB::table('page_builder_script_audit')->insert([
                    'entity_type' => 'component',
                    'entity_id' => $item->getKey(),
                    'action' => 'publish',
                    'user_id' => null,
                    'content_hash' => $hash,
                    'created_at' => now(),
                ]);
            } elseif (! $script->is_enabled) {
                $script->is_enabled = true;
                $script->save();
            }
        });

        $this->info('venues-map component published with versioned HTML/CSS/JS.');
        return self::SUCCESS;
    }

    private function source(string $filename): string
    {
        $path = resource_path('page-builder/'.$filename);
        $content = is_file($path) ? file_get_contents($path) : false;
        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException("Missing map source: {$filename}");
        }
        return $content;
    }
}
