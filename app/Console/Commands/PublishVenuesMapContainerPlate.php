<?php

namespace App\Console\Commands;

use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderScriptAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-time staging publication of the container-width homepage map plate.
 * Never touch other components or change the map JS. Refuse parallel Filament edits.
 */
final class PublishVenuesMapContainerPlate extends Command
{
    protected $signature = 'mskba:venues-map:container-plate {--check : Check the existing Filament state without writes} {--apply : Publish the HTML/CSS into the existing component}';

    protected $description = 'Put the interactive map in a versioned .container-width plate, with drift checks.';

    public function handle(PageBuilderPublisher $publisher): int
    {
        if (! app()->environment('staging')) {
            $this->error('Staging environment required.');
            return self::FAILURE;
        }

        if ((bool) $this->option('check') === (bool) $this->option('apply')) {
            $this->error('Choose exactly one of --check and --apply.');
            return self::FAILURE;
        }

        try {
            $oldHtml = $this->read('versions/venues-map-pre-container-20261022.html');
            $oldCss = $this->read('versions/venues-map-pre-container-20261022.css');
            $newHtml = $this->read('venues-map.html');
            $newCss = $this->read('venues-map.css');
            $expectedJsHash = hash('sha256', $this->read('venues-map.js'));

            if ($newHtml === $oldHtml || $newCss === $oldCss
                || ! str_contains($newHtml, 'container mskba-venues-map-section__container')) {
                throw new RuntimeException('Invalid container-plate source files.');
            }

            $status = DB::transaction(function () use (
                $publisher, $oldHtml, $oldCss, $newHtml, $newCss, $expectedJsHash
            ): string {
                $component = PageBuilderComponent::query()
                    ->where('slug', 'venues-map')->lockForUpdate()->firstOrFail();
                $script = PageBuilderScriptAsset::query()
                    ->where('entity_type', 'component')->where('entity_id', $component->getKey())
                    ->lockForUpdate()->firstOrFail();

                if ($component->published_at === null || ! $script->is_enabled
                    || $script->published_hash !== $expectedJsHash
                    || hash('sha256', (string) $script->published_js) !== $expectedJsHash
                    || hash('sha256', (string) $script->draft_js) !== $expectedJsHash) {
                    throw new RuntimeException('Published JS changed or map component not ready.');
                }

                if ($component->draft_html === $newHtml
                    && $component->published_html === $newHtml
                    && $component->draft_css === $newCss
                    && $component->published_css === $newCss) {
                    return 'ALREADY';
                }

                if ($component->draft_html !== $oldHtml
                    || $component->published_html !== $oldHtml
                    || $component->draft_css !== $oldCss
                    || $component->published_css !== $oldCss) {
                    throw new RuntimeException('HTML/CSS differs from the backed-up Filament version. No overwrite.');
                }

                if ($this->option('check')) return 'READY';

                // Standard Filament publication stores the preceding CSS and a
                // PageBuilderRevision snapshot. No JS, pages or templates are changed.
                $component->draft_html = $newHtml;
                $component->draft_css = $newCss;
                $component->save();
                $publisher->publish($component);

                return 'PUBLISHED';
            });

            $this->line($status);
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Map plate was not published: '.$exception->getMessage());
            return self::FAILURE;
        }
    }

    private function read(string $relative): string
    {
        $path = resource_path('page-builder/'.$relative);
        $data = is_file($path) ? file_get_contents($path) : false;
        if (! is_string($data) || trim($data) === '') {
            throw new RuntimeException('Missing component source: '.$relative);
        }
        return $data;
    }
}
