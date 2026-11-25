<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Enums\SeoEntityTypeEnum;
use App\Modules\Content\Domain\Models\PageSeoSetting;
use App\Modules\Content\Domain\Models\VenueCatalogLanding;
use App\Modules\Venue\Application\Services\VenueTypeFilter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

final readonly class VenueCatalogLandingEditor
{
    public function __construct(private ContentBodySanitizer $sanitizer) {}

    public function save(array $data, ?VenueCatalogLanding $record = null): VenueCatalogLanding
    {
        Gate::authorize('manage-content');
        $data = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'h1' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:180', 'regex:~\A'.VenueCatalogLandingNavigation::SLUG_PATTERN.'\z~D', Rule::unique('venue_catalog_landings', 'slug')->ignore($record?->getKey())],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'district_id' => ['nullable', 'integer', Rule::exists('districts', 'id')->where('city_id', $data['city_id'] ?? null)],
            ...VenueTypeFilter::rules($data['type'] ?? null),
            'operational_status' => ['nullable', Rule::enum(\App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum::class)],
            'access' => ['nullable', Rule::in(['free', 'paid', 'approval'])],
            'rental_configured' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'allow_indexing' => ['required', 'boolean'],
            'include_in_sitemap' => ['required', 'boolean'],
            'intro_text' => ['nullable', 'string', 'max:20000'],
            'body_text' => ['nullable', 'string', 'max:100000'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:320'],
        ])->validate();

        $data['type'] = VenueTypeFilter::canonical($data['type'] ?? null);
        foreach (['intro_text', 'body_text'] as $key) {
            $data[$key] = isset($data[$key]) ? $this->sanitizer->sanitize($data[$key]) : null;
        }
        $meta = Arr::only($data, ['meta_title', 'meta_description']);
        $record ??= new VenueCatalogLanding;
        try {
            return DB::transaction(function () use ($record, $data, $meta): VenueCatalogLanding {
                $record->fill(Arr::except($data, ['meta_title', 'meta_description']));
                $record->save();
                PageSeoSetting::query()->updateOrCreate([
                    'entity_type' => SeoEntityTypeEnum::VENUE_CATALOG_LANDING->value,
                    'entity_id' => $record->id,
                ], [
                    'meta_title' => $meta['meta_title'] ?? null,
                    'meta_description' => $meta['meta_description'] ?? null,
                ]);

                return $record;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => 'Этот ЧПУ или комбинация активных фильтров уже используется.']);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['type' => 'Укажите хотя бы один фильтр и проверьте принадлежность округа городу.']);
        }
    }
}
