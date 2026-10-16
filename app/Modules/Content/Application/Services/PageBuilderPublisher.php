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
    public function __construct(private readonly PageBuilderMarkup $markup) {}

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
                if (! preg_match('/\A\/(?:[a-z0-9]+(?:-[a-z0-9]+)*)?\z/D', $item->path)
                    || ($item->path !== '/' && in_array(ltrim($item->path, '/'), self::reservedPaths(), true))) {
                    throw ValidationException::withMessages(['path' => 'Недопустимый или системный URL.']);
                }
                if ($item->template_id !== null && ! PageBuilderTemplate::query()
                    ->whereKey($item->template_id)->whereNotNull('published_at')->exists()) {
                    throw ValidationException::withMessages(['template_id' => 'Сначала опубликуй выбранный шаблон.']);
                }
                if (PageBuilderPage::query()->where('published_path', $item->path)
                    ->where('id', '!=', $item->getKey())->exists()) {
                    throw ValidationException::withMessages(['path' => 'Этот URL уже занят другой опубликованной страницей.']);
                }
                // A saved URL is still a draft until the editor explicitly publishes it.
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

    public static function type(Model $record): string
    {
        return match (true) {
            $record instanceof PageBuilderPage => 'page',
            $record instanceof PageBuilderTemplate => 'template',
            $record instanceof PageBuilderComponent => 'component',
            default => throw new \InvalidArgumentException('Unknown builder entity'),
        };
    }

    public static function reservedPaths(): array
    {
        return ['account','admin','backoffice','api','login','register','logout','events',
            'teams','venues','participants','players','coaches','news','shop','privacy',
            'storage','assets','sitemap.xml','dashboard','tournaments','faq','partners',
            'page-builder','site-summary','integrations','sports-sections','r'];
    }

    private function snapshot(Model $item): array
    {
        $state = $item->only(['name', 'draft_html', 'draft_css']);
        if ($item instanceof PageBuilderPage) {
            $state += $item->only(['path', 'meta_title', 'meta_description', 'template_id']);
        }
        if ($item instanceof PageBuilderComponent && $item->slug === 'hero-slider') {
            $state += $item->only(['draft_slides', 'draft_options']);
        }
        return $state;
    }
}
