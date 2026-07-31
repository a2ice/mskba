<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Content\Domain\Enums\SeoEntityTypeEnum;
use App\Modules\Content\Domain\Models\PageSeoSetting;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AdminPageSeoController extends Controller
{
    public function index(Request $request): Response
    {
        $type = SeoEntityTypeEnum::tryFrom($request->string('entity_type')->toString())
            ?? SeoEntityTypeEnum::VENUE;
        $query = $this->query($type);

        if ($search = trim($request->string('q')->toString())) {
            $query->where($this->titleColumn($type), 'ilike', '%'.$search.'%');
        }

        $entities = $query->latest('id')->paginate(20)->withQueryString();
        $settings = PageSeoSetting::query()
            ->where('entity_type', $type->value)
            ->whereIn('entity_id', $entities->getCollection()->modelKeys())
            ->get()
            ->keyBy('entity_id');

        return ThemeResolver::page('admin.content.seo.index', [
            'entities' => $entities,
            'settings' => $settings,
            'types' => SeoEntityTypeEnum::cases(),
            'selectedType' => $type,
        ]);
    }

    public function edit(string $entityType, int $entityId): Response
    {
        $type = SeoEntityTypeEnum::from($entityType);
        $entity = $this->query($type)->findOrFail($entityId);
        $setting = PageSeoSetting::query()->firstOrNew([
            'entity_type' => $type->value,
            'entity_id' => $entityId,
        ]);

        return ThemeResolver::page('admin.content.seo.form', [
            'entity' => $entity,
            'entityTitle' => $this->entityTitle($entity, $type),
            'entityType' => $type,
            'setting' => $setting,
        ]);
    }

    public function update(Request $request, string $entityType, int $entityId): RedirectResponse
    {
        $type = SeoEntityTypeEnum::from($entityType);
        $this->query($type)->findOrFail($entityId);
        $validated = $request->validate([
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'meta_keywords' => ['nullable', 'string', 'max:2000'],
        ]);

        PageSeoSetting::query()->updateOrCreate(
            ['entity_type' => $type->value, 'entity_id' => $entityId],
            array_map(static fn (mixed $value): mixed => is_string($value) && trim($value) === '' ? null : $value, $validated),
        );

        return redirect()
            ->route('admin.content.seo.edit', [$type->value, $entityId])
            ->with('status', 'SEO-настройки сохранены.');
    }

    /** @return Builder<Model> */
    private function query(SeoEntityTypeEnum $type): Builder
    {
        return match ($type) {
            SeoEntityTypeEnum::VENUE => Venue::query(),
            SeoEntityTypeEnum::EVENT => Event::query()->whereNull('parent_event_id'),
            SeoEntityTypeEnum::TEAM => Team::query()->whereNull('temporary_for_event_id'),
        };
    }

    private function titleColumn(SeoEntityTypeEnum $type): string
    {
        return $type === SeoEntityTypeEnum::EVENT ? 'title' : 'name';
    }

    private function entityTitle(Model $entity, SeoEntityTypeEnum $type): string
    {
        return (string) $entity->getAttribute($this->titleColumn($type));
    }
}
