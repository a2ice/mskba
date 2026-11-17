<?php

namespace App\Console\Commands;

use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderScriptAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Manual, one-time v2 publication to the existing Filament component.
 * Only an exact, unchanged v1 baseline can be upgraded, after a full dev DB backup.
 * Later editorial edits in Filament are never overwritten by deploys.
 */
final class PublishHomepageVenuesMapV2 extends Command
{
    protected $signature = 'mskba:venues-map:publish-v2 {--check : Validate without writing anything} {--apply : Publish the new snapshot}';

    protected $description = 'Safely publish the custom pop-up map once; fail on any Filament draft or published drift.';

    private const V1 = '[mskba-venues-map:v1-installed]';
    private const V2 = '[mskba-venues-map:v2-installed]';

    public function handle(PageBuilderPublisher $publisher): int
    {
        if (! app()->environment('staging')) {
            $this->error('This operation is restricted to the staging environment.');
            return self::FAILURE;
        }

        if ((bool) $this->option('check') === (bool) $this->option('apply')) {
            $this->error('Specify exactly one of --check or --apply.');
            return self::FAILURE;
        }

        $component = PageBuilderComponent::query()->where('slug', 'venues-map')->first();
        if ($component === null) {
            $this->error('venues-map component is missing.');
            return self::FAILURE;
        }
        if (str_contains((string) $component->description, self::V2)) {
            $this->line('ALREADY');
            return self::SUCCESS;
        }

        $source = [
            'html' => $this->read('venues-map.html'),
            'css' => $this->read('venues-map.css'),
            'js' => $this->read('venues-map.js'),
        ];
        $v1 = [
            'html' => $this->read('versions/venues-map-v1.html'),
            'css' => $this->read('versions/venues-map-v1.css'),
            'js' => $this->read('versions/venues-map-v1.js'),
        ];

        try {
            $this->assertUnchanged($component, $v1);

            if ($this->option('check')) {
                $this->line('READY');
                return self::SUCCESS;
            }

            DB::transaction(function () use ($publisher, $component, $source, $v1): void {
                $record = PageBuilderComponent::query()
                    ->whereKey($component->getKey())->lockForUpdate()->firstOrFail();

                // Race-safe: repeat every assertion under database lock.
                $this->assertUnchanged($record, $v1);
                $record->draft_html = $source['html'];
                $record->draft_css = $source['css'];
                $record->description = trim((string) $record->description.' '.self::V2);
                if (mb_strlen((string) $record->description) > 500) {
                    throw new RuntimeException('Description marker exceeds column limit.');
                }
                $record->save();
                $publisher->publish($record);

                $script = PageBuilderScriptAsset::query()->where('entity_type', 'component')
                    ->where('entity_id', $record->getKey())->lockForUpdate()->firstOrFail();
                $script->previous_js = $script->published_js;
                $script->previous_hash = $script->published_hash;
                $script->published_js = $source['js'];
                $script->draft_js = $source['js'];
                $script->published_hash = hash('sha256', $source['js']);
                $script->published_at = now();
                $script->is_enabled = true;
                $script->save();

                DB::table('page_builder_script_audit')->insert([
                    'entity_type' => 'component',
                    'entity_id' => $record->getKey(),
                    'action' => 'publish',
                    'user_id' => null,
                    'content_hash' => $script->published_hash,
                    'created_at' => now(),
                ]);
            });

            $this->info('venues-map v2 published with independent HTML/CSS/JS version history.');
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Refusing to replace a changed component: '.$exception->getMessage());
            return self::FAILURE;
        }
    }

    /** @param array{html:string,css:string,js:string} $v1 */
    private function assertUnchanged(PageBuilderComponent $component, array $v1): void
    {
        if (! str_contains((string) $component->description, self::V1)
            || $component->published_at === null) {
            throw new RuntimeException('The original v1 installer marker or publication is missing.');
        }
        foreach (['html', 'css'] as $kind) {
            foreach (['draft_'.$kind, 'published_'.$kind] as $field) {
                if (hash('sha256', (string) $component->{$field}) !== hash('sha256', $v1[$kind])) {
                    throw new RuntimeException('Filament '.$field.' differs from the approved v1 baseline.');
                }
            }
        }
        $asset = PageBuilderScriptAsset::query()
            ->where('entity_type', 'component')
            ->where('entity_id', $component->getKey())->first();
        if ($asset === null || ! $asset->is_enabled
            || (string) $asset->draft_js !== $v1['js']
            || (string) $asset->published_js !== $v1['js']
            || (string) $asset->published_hash !== hash('sha256', $v1['js'])) {
            throw new RuntimeException('Filament JS differs from the approved v1 baseline.');
        }
    }

    private function read(string $relative): string
    {
        $path = resource_path('page-builder/'.$relative);
        $content = is_file($path) ? file_get_contents($path) : false;
        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Missing map source: '.$relative);
        }
        return $content;
    }
}
