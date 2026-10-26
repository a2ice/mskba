<?php

namespace App\Console\Commands;

use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderScriptAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Single, guarded DEV upgrade of the live Page Builder map component.
 *
 * The visual CTA and collapse button are handled in JS so the approved HTML
 * remains byte-for-byte unchanged. Never clobber Filament editorial edits.
 */
final class PublishVenuesMapExpandToggle extends Command
{
    protected $signature = 'mskba:venues-map:expand-toggle {--check : Read-only baseline check} {--apply : Publish the overlay CSS and toggle JS}';

    protected $description = 'Allow the homepage map overlay to slide away and return, with guarded staging-only publication.';

    public function handle(PageBuilderPublisher $publisher): int
    {
        if (! app()->environment('staging')) {
            $this->error('Only the isolated staging environment may publish the map toggle.');

            return self::FAILURE;
        }
        if ((bool) $this->option('check') === (bool) $this->option('apply')) {
            $this->error('Choose exactly one of --check or --apply.');

            return self::FAILURE;
        }

        try {
            $oldJs = $this->read('versions/venues-map-pre-expand-20261026.js');
            $oldCss = $this->read('versions/venues-map-pre-expand-20261026.css');
            $newJs = $this->read('venues-map.js');
            $newCss = $this->read('venues-map.css');
            $expectedHtml = $this->read('venues-map.html');

            if ($oldJs === $newJs || $oldCss === $newCss
                || ! str_contains($newJs, 'is-map-expanded')
                || ! str_contains($newCss, '.mskba-venues-map.is-map-expanded')) {
                throw new RuntimeException('The approved JS/CSS change set is missing or invalid.');
            }

            $result = DB::transaction(function () use (
                $publisher, $oldJs, $oldCss, $newJs, $newCss, $expectedHtml
            ): string {
                $component = PageBuilderComponent::query()->where('slug', 'venues-map')
                    ->lockForUpdate()->firstOrFail();
                $script = PageBuilderScriptAsset::query()
                    ->where('entity_type', 'component')
                    ->where('entity_id', $component->getKey())
                    ->lockForUpdate()->firstOrFail();

                if ($component->published_at === null || ! $script->is_enabled
                    || $component->published_html !== $expectedHtml
                    || $component->draft_html !== $expectedHtml) {
                    throw new RuntimeException('Unexpected map HTML or unpublished/disabled map component. No changes.');
                }

                $oldHash = hash('sha256', $oldJs);
                $newHash = hash('sha256', $newJs);
                if ($component->published_css === $newCss
                    && $component->draft_css === $newCss
                    && $script->published_js === $newJs
                    && $script->draft_js === $newJs
                    && $script->published_hash === $newHash) {
                    return 'ALREADY';
                }

                if ($component->published_css !== $oldCss
                    || $component->draft_css !== $oldCss
                    || $script->published_js !== $oldJs
                    || $script->draft_js !== $oldJs
                    || $script->published_hash !== $oldHash) {
                    throw new RuntimeException('Map CSS/JS differs from the preserved pre-expand snapshot. Refusing to overwrite Filament edits.');
                }

                if ($this->option('check')) {
                    return 'READY';
                }

                // The official CSS path keeps the previous-css rollback slot and audit revision.
                $publisher->saveAndPublishCss($component, $newCss);

                // Store the complete previous JS snapshot for a one-action rollback.
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

            $this->info($result);

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error('Map toggle not published: '.$error->getMessage());

            return self::FAILURE;
        }
    }

    private function read(string $filename): string
    {
        $path = resource_path('page-builder/'.$filename);
        $content = is_file($path) ? file_get_contents($path) : false;
        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Missing map source: '.$filename);
        }

        return $content;
    }
}
