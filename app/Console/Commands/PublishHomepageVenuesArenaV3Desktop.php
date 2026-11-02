<?php

namespace App\Console\Commands;

use App\Modules\Content\Application\Services\PageBuilderMarkup;
use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Content\Domain\Models\PageBuilderRevision;
use App\Modules\Content\Domain\Models\PageBuilderScriptAsset;
use App\Modules\Media\Domain\Models\SiteMediaAsset;
use App\Modules\Media\Domain\Models\SiteMediaFolder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-time, fail-closed staging publication of the approved Arena v3 layout.
 *
 * All editor-owned HTML/CSS and the existing single-ball JS are read from
 * Filament's published database records. Never use a Git source snapshot to
 * replace a customized component, and never touch main/production.
 */
final class PublishHomepageVenuesArenaV3Desktop extends Command
{
    protected $signature = 'mskba:venues-arena-v3:desktop {--check : Inspect prerequisites without publishing} {--apply : Publish the reviewed changes on staging}';

    protected $description = 'One-time guarded desktop Arena v3 layout and ball handoff in Filament.';

    private const INSTALL_MARKER = '[mskba-venues-v3:desktop-20261102-installed]';

    private const CSS_MARKER = 'mskba-venues-v3-desktop-20261102';

    private const JS_MARKER = 'mskba-venues-v3-handoff-20261102';

    public function handle(PageBuilderPublisher $publisher, PageBuilderMarkup $markup): int
    {
        if (! app()->environment('staging') || config('app.url') !== 'https://dev.mskba.ru') {
            $this->error('Refusing to operate outside https://dev.mskba.ru (APP_ENV=staging).');

            return self::FAILURE;
        }

        if ((bool) $this->option('check') === (bool) $this->option('apply')) {
            $this->error('Specify exactly one of --check or --apply.');

            return self::FAILURE;
        }

        try {
            $status = DB::transaction(function () use ($publisher, $markup): string {
                $page = PageBuilderPage::query()
                    ->where('published_path', '/')->lockForUpdate()->firstOrFail();
                $component = PageBuilderComponent::query()
                    ->where('slug', 'venues-scene-v3')->lockForUpdate()->firstOrFail();
                $script = PageBuilderScriptAsset::query()
                    ->where('entity_type', 'page')->where('entity_id', $page->getKey())
                    ->lockForUpdate()->firstOrFail();

                if (! str_contains((string) $page->published_html, 'name="venues-scene-v3"')
                    || $page->published_at === null
                    || $component->published_at === null
                    || ! $script->is_enabled
                    || $script->published_js === null) {
                    throw new RuntimeException('Homepage, Arena v3, or enabled ball JS is not published as expected.');
                }

                if (str_contains((string) $component->description, self::INSTALL_MARKER)) {
                    return 'ALREADY';
                }

                $html = (string) $component->published_html;
                $css = (string) $component->published_css;
                $js = (string) $script->published_js;

                $installedCss = str_contains($css, self::CSS_MARKER);
                $installedJs = str_contains($js, self::JS_MARKER);
                $installedHtml = str_contains($html, 'mskba-v3-venues__shooter-frame');

                if ($installedCss && $installedJs && $installedHtml) {
                    $this->info('Arena v3 desktop has already been installed. Keep subsequent Filament edits intact.');

                    return 'ALREADY';
                }

                // HTML may have been prepared already via the Filament editor.
                // If so, preserve it byte-for-byte and only publish the absent CSS/JS.
                if ($installedCss || $installedJs) {
                    throw new RuntimeException('CSS or JS contains an unexpected partial install marker.');
                }
                if ($installedHtml
                    && (substr_count($html, 'mskba-v3-venues__shooter-frame') !== 1
                        || substr_count($html, 'mskba-venues-scene__ball-target') !== 1
                        || ! str_contains($html, 'mskba-venues-scene__visual-space'))) {
                    throw new RuntimeException('Existing shooter frame lacks the verified ball target structure.');
                }

                // Never replace a user's unsaved/unpublished editorial work.
                if ((string) $component->draft_html !== $html
                    || (string) $component->draft_css !== $css
                    || (string) $script->draft_js !== $js) {
                    throw new RuntimeException('Filament has unpublished HTML/CSS/JS edits. Nothing was changed.');
                }

                if (! str_contains($html, 'class="mskba-v3-venues__foreground"')
                    || ! str_contains($html, 'id="venues-scene"')) {
                    throw new RuntimeException('Unexpected Arena v3 HTML structure.');
                }

                $this->verifyAssets();
                $nextHtml = $installedHtml ? $html : $this->prepareHtml($html);
                $nextCss = rtrim($css)."\n\n".$this->desktopCss()."\n";
                $nextJs = $this->prepareJs($js);

                $markup->validate($nextHtml, $nextCss);
                if (! str_contains($markup->html($nextHtml), 'mskba-venues-scene__ball-target')) {
                    throw new RuntimeException('HTML sanitizer removed the ball target.');
                }

                if ($this->option('check')) {
                    return 'READY';
                }

                // A durable, restorable pre-change snapshot separate from the
                // standard last-two-publications compaction window.
                PageBuilderRevision::query()->create([
                    'entity_type' => 'component',
                    'entity_id' => $component->getKey(),
                    'action' => 'venues_v3_desktop_backup',
                    'snapshot' => [
                        'draft_html' => $component->draft_html,
                        'published_html' => $component->published_html,
                        'draft_css' => $component->draft_css,
                        'published_css' => $component->published_css,
                        'published_at' => $component->published_at?->toIso8601String(),
                    ],
                    'user_id' => null,
                    'created_at' => now(),
                ]);
                PageBuilderRevision::query()->create([
                    'entity_type' => 'page',
                    'entity_id' => $page->getKey(),
                    'action' => 'venues_v3_ball_js_backup',
                    'snapshot' => [
                        'script_entity_type' => $script->entity_type,
                        'script_entity_id' => $script->entity_id,
                        'draft_js' => $script->draft_js,
                        'published_js' => $script->published_js,
                        'previous_js' => $script->previous_js,
                        'published_hash' => $script->published_hash,
                        'previous_hash' => $script->previous_hash,
                        'is_enabled' => $script->is_enabled,
                    ],
                    'user_id' => null,
                    'created_at' => now(),
                ]);

                $description = trim((string) $component->description.' '.self::INSTALL_MARKER);
                if (mb_strlen($description) > 500) {
                    throw new RuntimeException('Component description marker would exceed 500 characters.');
                }
                $component->description = $description;
                $component->draft_html = $nextHtml;
                $component->draft_css = $nextCss;
                $component->save();
                $publisher->publish($component);

                $script->previous_js = $js;
                $script->previous_hash = $script->published_hash;
                $script->draft_js = $nextJs;
                $script->published_js = $nextJs;
                $script->published_hash = hash('sha256', $nextJs);
                $script->published_at = now();
                $script->save();

                DB::table('page_builder_script_audit')->insert([
                    'entity_type' => 'page',
                    'entity_id' => $page->getKey(),
                    'action' => 'publish',
                    'user_id' => null,
                    'content_hash' => $script->published_hash,
                    'created_at' => now(),
                ]);

                return 'PUBLISHED';
            }, 3);

            $this->info('Arena v3 desktop result: '.$status);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Arena v3 desktop was not changed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function verifyAssets(): void
    {
        $folder = SiteMediaFolder::query()->where('name', 'Home venue section')
            ->orWhere('slug', 'home-venue-section')->first();
        if ($folder === null) {
            throw new RuntimeException('The Home venue section media folder was not found.');
        }

        $names = ['arena', 'court', 'bench', 'hoop-arena', 'player-shooting', 'player-defending'];
        $assets = SiteMediaAsset::query()->where('folder_id', $folder->getKey())
            ->whereIn('title', $names)->whereNotNull('current_media_id')
            ->pluck('title')->all();
        if (count(array_unique($assets)) !== count($names)) {
            throw new RuntimeException('One or more Arena v3 media assets are missing or unavailable.');
        }
    }

    private function prepareHtml(string $html): string
    {
        $pattern = '~<div class="mskba-v3-venues__foreground">([\s\S]*?)</div>~';
        if (preg_match_all($pattern, $html, $found, PREG_SET_ORDER) !== 1) {
            throw new RuntimeException('Expected exactly one foreground with three separate images.');
        }

        $content = $found[0][1];
        $images = [];
        foreach (['hoop', 'defender', 'shooter'] as $name) {
            $patternForImage = '~<img\b[^>]*class="mskba-v3-venues__'.$name.'"[^>]*>~';
            if (preg_match_all($patternForImage, $content, $tags) !== 1) {
                throw new RuntimeException('Missing or duplicate image in foreground: '.$name);
            }
            $images[$name] = $tags[0][0];
        }

        $replacement = '<div class="mskba-v3-venues__foreground mskba-venues-scene__visual-space">'
            ."\n    ".$images['hoop']
            ."\n    ".$images['defender']
            ."\n    ".'<div class="mskba-v3-venues__shooter-frame">'
            ."\n      ".$images['shooter']
            ."\n      ".'<span class="mskba-v3-venues__ball-target mskba-venues-scene__ball-target"></span>'
            ."\n    </div>\n  </div>";

        $result = str_replace($found[0][0], $replacement, $html, $count);
        if ($count !== 1) {
            throw new RuntimeException('Foreground replacement was not unique.');
        }

        return $result;
    }

    private function desktopCss(): string
    {
        $path = resource_path('page-builder/venues-scene-v3-desktop-hotfix.css');
        $source = is_file($path) ? file_get_contents($path) : false;
        if (! is_string($source) || ! str_contains($source, self::CSS_MARKER)) {
            throw new RuntimeException('Missing/invalid reviewed desktop stylesheet.');
        }

        return trim($source);
    }

    private function prepareJs(string $js): string
    {
        $oldContext = <<<'JS'
    const story = scene?.closest?.('.mskba-home-story') || null;
    const venues = story?.querySelector?.('#venues-intro') || null;
    const ballTarget = venues?.querySelector?.('.mskba-venues-scene__ball-target') || null;
    const ballHost = venues?.querySelector?.('.mskba-venues-scene__visual-space') || null;
JS;
        $newContext = <<<'JS'
    /* mskba-venues-v3-handoff-20261102 */
    const story = scene?.closest?.('.mskba-home-story') || null;
    const venuesV3 = document.querySelector('#venues-scene');
    const venues = venuesV3?.querySelector('.mskba-venues-scene__ball-target')
        ? venuesV3
        : (story?.querySelector('#venues-intro') || null);
    const ballTarget = venues?.querySelector('.mskba-venues-scene__ball-target') || null;
    const ballHost = venues?.querySelector('.mskba-venues-scene__visual-space') || null;
JS;
        $next = $this->replaceOnce($js, $oldContext, $newContext);

        $next = $this->replaceOnce(
            $next,
            'if (!story || !venues || !ballTarget || !ballHost) return false;',
            "if (!venues || !ballTarget || !ballHost) return false;\n"
            ."        if (venues === venuesV3 && !window.matchMedia('(min-width: 1100px)').matches) return false;",
        );

        $pattern = '~(desktop\s*:\s*\{[^}]*\bto\s*:\s*)\[\s*\d+\s*,\s*\d+\s*\]~';
        if (preg_match_all($pattern, $next) !== 1) {
            throw new RuntimeException('Unrecognized desktop rollingBallProfiles configuration.');
        }

        $next = preg_replace($pattern, '1[760, 890]', $next, 1, $count);
        if (! is_string($next) || $count !== 1) {
            throw new RuntimeException('Cannot update the desktop ball endpoint.');
        }

        return $next;
    }

    private function replaceOnce(string $source, string $before, string $after): string
    {
        if (substr_count($source, $before) !== 1) {
            throw new RuntimeException('Published ball JS differs from the reviewed source; refusing overwrite.');
        }

        return str_replace($before, $after, $source);
    }
}
