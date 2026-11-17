<?php

namespace App\Console\Commands;

use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Content\Domain\Models\PageBuilderScriptAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-time safe installation on staging. Homepage keeps its previous state
 * until the component is published. All modifications are one DB transaction.
 * A pre-install verified pg_dump is enforced by the calling ops workflow.
 */
final class InstallHomepageTournamentsScene extends Command
{
    protected $signature = 'mskba:tournaments-scene:install {--check : Validate prerequisites without changing DB} {--apply : Create and publish the component and place it on the homepage}';
    protected $description = 'Install full-width Filament tournaments-scene and replace legacy home tournaments plate.';

    private const ORIGINAL_HOME_HASH = 'e1ae11f26a0f883c01405fb4edb4af30dbace78aa48b38851a0a96bfd493cf19';
    private const COMPONENT_SLUG = 'tournaments-scene';
    private const REPLACEMENT = '<section id="tournaments"><mskba-component name="tournaments-scene"></mskba-component></section>';

    public function handle(PageBuilderPublisher $publisher): int
    {
        if (! app()->environment('staging')) {
            $this->error('Only staging is permitted.');
            return self::FAILURE;
        }
        if ((bool) $this->option('check') === (bool) $this->option('apply')) {
            $this->error('Choose exactly one of --check / --apply.');
            return self::FAILURE;
        }

        try {
            $html = $this->source('html');
            $css = $this->source('css');
            $js = $this->source('js');
            $result = DB::transaction(function () use ($publisher, $html, $css, $js): string {
                $page = PageBuilderPage::query()->where('published_path', '/')
                    ->lockForUpdate()->firstOrFail();
                $existing = PageBuilderComponent::query()
                    ->where('slug', self::COMPONENT_SLUG)->lockForUpdate()->first();
                $already = str_contains((string) $page->published_html, self::REPLACEMENT);

                if ($already) {
                    if (! $existing || $existing->published_at === null) {
                        throw new RuntimeException('Page refers to a missing/unpublished tournaments component.');
                    }
                    $script = PageBuilderScriptAsset::query()->where('entity_type', 'component')
                        ->where('entity_id', $existing->getKey())->first();
                    if ($existing->published_html === $html
                        && $existing->draft_html === $html
                        && $existing->published_css === $css
                        && $existing->draft_css === $css
                        && $script?->published_js === $js
                        && $script?->draft_js === $js
                        && $script?->is_enabled) {
                        return 'ALREADY';
                    }
                    throw new RuntimeException('Tournament component already exists with divergent content.');
                }
                if ($existing !== null) {
                    throw new RuntimeException('Component slug already taken by another draft. Refusing overwrite.');
                }
                if (hash('sha256', (string) $page->published_html) !== self::ORIGINAL_HOME_HASH
                    || $page->draft_html !== $page->published_html) {
                    throw new RuntimeException('Homepage HTML has changed since inspection. No overwrite.');
                }
                $pattern = '~<section\s+id="tournaments"(?=\s|>)[^>]*>[\s\S]*?</section>~i';
                $matches = [];
                if (preg_match_all($pattern, (string) $page->published_html, $matches) !== 1
                    || ! str_contains($matches[0][0] ?? '', 'bordered-plate')) {
                    throw new RuntimeException('Expected legacy tournaments plate was not found exactly once.');
                }
                $nextHtml = preg_replace($pattern, self::REPLACEMENT, (string) $page->published_html, 1, $count);
                if ($count !== 1 || ! is_string($nextHtml)) {
                    throw new RuntimeException('Unable to replace exactly one tournaments section.');
                }
                if ($this->option('check')) {
                    return 'READY';
                }

                $component = PageBuilderComponent::query()->create([
                    'slug' => self::COMPONENT_SLUG,
                    'name' => 'Главная — Турниры',
                    'description' => 'Full-width сцена турниров. Медиа: home-tournaments-section/background, rim, ball.',
                    'draft_html' => $html,
                    'draft_css' => $css,
                ]);
                $publisher->publish($component);

                $hash = hash('sha256', $js);
                PageBuilderScriptAsset::query()->create([
                    'entity_type' => 'component',
                    'entity_id' => $component->getKey(),
                    'draft_js' => $js,
                    'published_js' => $js,
                    'published_hash' => $hash,
                    'published_at' => now(),
                    'is_enabled' => true,
                ]);
                DB::table('page_builder_script_audit')->insert([
                    'entity_type' => 'component',
                    'entity_id' => $component->getKey(),
                    'action' => 'publish',
                    'user_id' => null,
                    'content_hash' => $hash,
                    'created_at' => now(),
                ]);

                $page->draft_html = $nextHtml;
                $page->save();
                $publisher->publish($page);

                return 'PUBLISHED';
            });

            $this->line($result);
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Tournament scene not installed: '.$exception->getMessage());
            return self::FAILURE;
        }
    }

    private function source(string $extension): string
    {
        $file = resource_path('page-builder/tournaments-scene.'.$extension);
        $source = is_file($file) ? file_get_contents($file) : false;
        if (! is_string($source) || trim($source) === '') {
            throw new RuntimeException('Missing source: tournaments-scene.'.$extension);
        }
        return $source;
    }
}
