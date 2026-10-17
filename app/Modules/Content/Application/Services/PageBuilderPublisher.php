<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Content\Domain\Models\PageBuilderRevision;
use App\Modules\Content\Domain\Models\PageBuilderTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PageBuilderPublisher
{
    public function __construct(
        private readonly PageBuilderMarkup $markup,
        private readonly PageBuilderSources $sources,
        private readonly PageBuilderRouteRegistry $routeRegistry,
    ) {}

    public function publish(Model $record): void
    {
        if (! $record instanceof PageBuilderPage
            && ! $record instanceof PageBuilderTemplate
            && ! $record instanceof PageBuilderComponent) {
            throw new \InvalidArgumentException('Unsupported builder record');
        }

        DB::transaction(function () use ($record): void {
            $item = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $this->markup->validate((string) $item->draft_html, $item->draft_css);
            if ($item instanceof PageBuilderPage) {
                $route = $this->routeRegistry->forPublication($item->path);
                if ($item->template_id !== null && ! PageBuilderTemplate::query()
                    ->whereKey($item->template_id)->whereNotNull('published_at')->exists()) {
                    throw ValidationException::withMessages(['template_id' => 'Сначала опубликуй выбранный шаблон.']);
                }
                if (PageBuilderPage::query()->where('published_path', $item->path)
                    ->where('id', '!=', $item->getKey())->exists()) {
                    throw ValidationException::withMessages(['path' => 'Этот URL уже занят другой опубликованной страницей.']);
                }
                // Preview resolves draft components, while public pages require published ones.
                // Reject missing dependencies BEFORE writing any public snapshot.
                $this->assertComponentsPublished($item);
                // A saved URL is still a draft until the editor explicitly publishes it.
                $item->published_route_type = $route['type'];
                $item->published_route_name = $route['route_name'];
                $item->cms_override_enabled = $route['route_name'] !== null;
                $item->published_path = $item->path;
                $item->published_name = $item->name;
                $item->published_meta_title = $item->meta_title;
                $item->published_meta_description = $item->meta_description;
                $item->published_template_id = $item->template_id;
            }
            if ($item instanceof PageBuilderComponent && $item->slug === 'hero-slider') {
                HeroSliderSettings::validate($item->draft_slides, $item->draft_options);
                $item->published_slides = $item->draft_slides;
                $item->published_options = HeroSliderSettings::options($item->draft_options);
            }
            $item->published_html = $item->draft_html;
            // Independent CSS rollback state; HTML-only publishes never
            // replace the previous CSS version.
            if ($item->published_at !== null
                && (string) $item->published_css !== (string) $item->draft_css) {
                $item->previous_css = $item->published_css;
                $item->has_previous_css = true;
            }
            $item->published_css = $item->draft_css;
            $item->published_at = now();
            $item->save();
            PageBuilderRevision::query()->create([
                'entity_type' => self::type($item),
                'entity_id' => $item->getKey(),
                'action' => 'publish',
                'snapshot' => $this->snapshot($item),
                'user_id' => auth('web')->id(),
                'created_at' => now(),
            ]);
            // Only the latest two *full* publication snapshots remain restorable.
            // Older entries keep tiny audit metadata, not CSS/HTML/slider copies.
            $this->compactPublishedHistory(self::type($item), (int) $item->getKey());
        });
    }

    private function compactPublishedHistory(string $entityType, int $entityId): void
    {
        $ids = PageBuilderRevision::query()
            ->where('entity_type', $entityType)->where('entity_id', $entityId)
            ->where('action', 'publish')->orderByDesc('id')->limit(2)->pluck('id')->all();

        PageBuilderRevision::query()
            ->where('entity_type', $entityType)->where('entity_id', $entityId)
            ->where('action', 'publish')->whereNotIn('id', $ids)
            ->orderBy('id')->chunkById(100, static function ($revisions): void {
                foreach ($revisions as $revision) {
                    $snapshot = $revision->snapshot;
                    $revision->update([
                        'action' => 'publish_audit',
                        'snapshot' => [
                            'sha256' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)),
                            'archived' => true,
                        ],
                    ]);
                }
            });
    }

    /** Save CSS draft only, with no effect on the published page. */
    public function saveCssDraft(Model $record, ?string $source): void
    {
        $css = (string) $source;
        $this->markup->css($css);

        DB::transaction(function () use ($record, $css): void {
            $item = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $item->draft_css = $css;
            $item->save();
        });
    }

    /**
     * Save CSS without publishing unrelated pending HTML, layout, slider or
     * template edits. If the entity is already published, deploy only its CSS
     * and rotate the previous CSS slot when content actually changed.
     *
     * A new, unpublished entity can stage CSS; its first complete publication
     * still uses the bottom Save and Publish page action.
     *
     * @return bool Whether CSS was published to an existing public entity.
     */
    public function saveAndPublishCss(Model $record, ?string $source): bool
    {
        if (! $record instanceof PageBuilderPage
            && ! $record instanceof PageBuilderTemplate
            && ! $record instanceof PageBuilderComponent) {
            throw new \InvalidArgumentException('Unsupported builder record');
        }

        $css = (string) $source;
        $this->markup->css($css);

        return DB::transaction(function () use ($record, $css): bool {
            $item = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $item->draft_css = $css;

            if ($item->published_at === null) {
                $item->save();

                return false;
            }

            if ((string) $item->published_css !== $css) {
                $item->previous_css = $item->published_css;
                $item->has_previous_css = true;
                $item->published_css = $css;
                PageBuilderRevision::query()->create([
                    'entity_type' => self::type($item),
                    'entity_id' => $item->getKey(),
                    'action' => 'css_publish',
                    'snapshot' => ['sha256' => hash('sha256', $css)],
                    'user_id' => auth('web')->id(),
                    'created_at' => now(),
                ]);
            }
            $item->save();

            return true;
        });
    }

    /**
     * Swap current and previous published CSS only. HTML, component data and
     * CMS override status remain untouched. Draft CSS follows the rollback so
     * the next publish does not immediately undo the restored design.
     */
    public function rollbackCss(Model $record): void
    {
        DB::transaction(function () use ($record): void {
            $item = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if (! $item->has_previous_css || $item->published_at === null) {
                throw ValidationException::withMessages(['draft_css' => 'Предыдущая версия CSS отсутствует.']);
            }
            $current = $item->published_css;
            $item->published_css = $item->previous_css;
            $item->previous_css = $current;
            $item->draft_css = $item->published_css;
            $item->save();

            PageBuilderRevision::query()->create([
                'entity_type' => self::type($item),
                'entity_id' => $item->getKey(),
                'action' => 'css_rollback',
                'snapshot' => ['sha256' => hash('sha256', (string) $item->published_css)],
                'user_id' => auth('web')->id(),
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Reversible switch between the published CMS snapshot and original Laravel
     * handler. Draft state is unchanged, and the action is audit recorded.
     */
    public function setOverrideEnabled(PageBuilderPage $page, bool $enabled): void
    {
        DB::transaction(function () use ($page, $enabled): void {
            $item = PageBuilderPage::query()->whereKey($page->getKey())->lockForUpdate()->firstOrFail();
            if ($item->published_at === null
                || ! is_string($item->published_route_name)
                || $item->published_route_type !== 'public_index'
                || ! $this->routeRegistry->mayOverride($item->published_route_name, $item->published_path)) {
                throw ValidationException::withMessages([
                    'path' => 'Для этого опубликованного маршрута переключение CMS недоступно.',
                ]);
            }

            if ((bool) $item->cms_override_enabled === $enabled) {
                return;
            }
            $item->cms_override_enabled = $enabled;
            $item->save();
            PageBuilderRevision::query()->create([
                'entity_type' => 'page',
                'entity_id' => $item->getKey(),
                'action' => $enabled ? 'override_on' : 'override_off',
                'snapshot' => [
                    'name' => $item->name,
                    'path' => $item->path,
                    'route_name' => $item->published_route_name,
                    'cms_override_enabled' => $enabled,
                ],
                'user_id' => auth('web')->id(),
                'created_at' => now(),
            ]);
        });
    }

    public function restoreDraft(Model $record, int $revisionId): void
    {
        DB::transaction(function () use ($record, $revisionId): void {
            $item = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $revision = PageBuilderRevision::query()
                ->where('entity_type', self::type($item))->where('entity_id', $item->getKey())
                ->findOrFail($revisionId);
            $state = (array) $revision->snapshot;
            foreach (['draft_html', 'draft_css', 'draft_slides', 'draft_options', 'name', 'path', 'meta_title', 'meta_description', 'template_id'] as $key) {
                if (array_key_exists($key, $state) && $item->isFillable($key)) {
                    $item->{$key} = $state[$key];
                }
            }
            $item->save();
            // Restore never changes the published copy.
        });
    }


    /** Ensure publication cannot silently replace a previewed component with empty HTML. */
    private function assertComponentsPublished(PageBuilderPage $page): void
    {
        $templateHtml = $page->template_id !== null
            ? (string) PageBuilderTemplate::query()->whereKey($page->template_id)->value('published_html')
            : '[[page_content]]';
        $effectiveHtml = str_replace('[[page_content]]', (string) $page->draft_html, $templateHtml);
        $matches = [];
        preg_match_all(
            '~<mskba-component\b(?P<attrs>[^>]*)>\s*</mskba-component>~i',
            $effectiveHtml,
            $matches,
            PREG_SET_ORDER,
        );

        // Match the renderer's supported placeholder grammar exactly.
        if (preg_match_all('~<mskba-component\b~i', $effectiveHtml) !== count($matches)) {
            throw ValidationException::withMessages([
                'draft_html' => 'Неверный синтаксис компонента. Используй <mskba-component name="..."></mskba-component>.',
            ]);
        }

        $required = [];
        foreach ($matches as $match) {
            if (! preg_match('/\bname="([a-z][a-z0-9_-]{1,79})"/', $match['attrs'], $nameMatch)) {
                throw ValidationException::withMessages([
                    'draft_html' => 'У компонента отсутствует корректный атрибут name.',
                ]);
            }
            $name = $nameMatch[1];
            if ($name === 'games-list') {
                preg_match('/\bsource="([^"]*)"/', $match['attrs'], $sourceMatch);
                if (! $this->sources->isRegistered((string) ($sourceMatch[1] ?? ''))) {
                    throw ValidationException::withMessages([
                        'draft_html' => 'Для games-list укажи зарегистрированный источник данных source.',
                    ]);
                }
                continue;
            }
            $required[$name] = true;
        }

        if ($required === []) {
            return;
        }

        $published = PageBuilderComponent::query()
            ->whereIn('slug', array_keys($required))
            ->whereNotNull('published_at')
            ->pluck('slug')->all();
        $missing = array_diff(array_keys($required), $published);
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'draft_html' => 'Сначала опубликуй компоненты в «Дизайн → Компоненты»: '
                    .implode(', ', $missing).'. Страница не опубликована; текущая публичная версия сохранена.',
            ]);
        }
    }

    public static function type(Model $record): string
    {
        return match (true) {
            $record instanceof PageBuilderPage => 'page',
            $record instanceof PageBuilderTemplate => 'template',
            $record instanceof PageBuilderComponent => 'component',
            default => throw new \InvalidArgumentException('Unknown builder entity'),
        };
    }

    private function snapshot(Model $item): array
    {
        $state = $item->only(['name', 'draft_html', 'draft_css']);
        if ($item instanceof PageBuilderPage) {
            $state += $item->only(['path', 'meta_title', 'meta_description', 'template_id']);
            $state['published_route_type'] = $item->published_route_type;
            $state['published_route_name'] = $item->published_route_name;
        }
        if ($item instanceof PageBuilderComponent && $item->slug === 'hero-slider') {
            $state += $item->only(['draft_slides', 'draft_options']);
        }
        return $state;
    }
}
