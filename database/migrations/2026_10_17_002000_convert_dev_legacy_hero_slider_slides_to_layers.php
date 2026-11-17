<?php

use App\Modules\Content\Application\Services\HeroSliderLegacySlideConverter;
use App\Modules\Content\Application\Services\HeroSliderSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // One-off DEV-only conversion. Never modify a production or preview DB.
        if (! app()->environment('staging')
            || config('app.url') !== 'https://dev.mskba.ru') {
            echo "HERO_LEGACY_CONVERSION: skipped non-DEV\n";

            return;
        }

        DB::transaction(function (): void {
            $query = DB::table('page_builder_components')->where('slug', 'hero-slider');
            $record = $query->lockForUpdate()->first();
            if ($record !== null && ! property_exists($record, 'draft_slides')) {
                throw new \RuntimeException('Hero Slider schema is missing draft_slides.');
            }
            if (! $record) {
                echo "HERO_LEGACY_CONVERSION: no component\n";

                return;
            }

            $converter = new HeroSliderLegacySlideConverter();
            $changes = [];
            $converted = [];
            foreach (['draft_slides', 'published_slides'] as $column) {
                if ($record->{$column} === null) {
                    continue;
                }
                $original = json_decode($record->{$column}, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($original) || ! array_is_list($original)) {
                    throw new \UnexpectedValueException('Unexpected Hero Slider JSON shape.');
                }
                $result = $converter->convert($original);
                if ($result['converted'] > 0) {
                    $options = $column === 'draft_slides'
                        ? $record->draft_options : $record->published_options;
                    HeroSliderSettings::validate(
                        $result['slides'],
                        $options === null ? [] : json_decode($options, true, 512, JSON_THROW_ON_ERROR),
                    );
                    $changes[$column] = json_encode($result['slides'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                }
                $converted[$column] = $result['converted'];
            }
            if ($changes === []) {
                echo "HERO_LEGACY_CONVERSION: no legacy slides\n";

                return;
            }

            DB::table('page_builder_revisions')->insert([
                'entity_type' => 'component',
                'entity_id' => $record->id,
                'action' => 'layout_backup',
                'snapshot' => json_encode([
                    'patch' => 'dev_legacy_hero_conversion_20261017',
                    'name' => $record->name,
                    'draft_html' => $record->draft_html,
                    'draft_css' => $record->draft_css,
                    'draft_slides' => json_decode($record->draft_slides ?? 'null', true),
                    'draft_options' => json_decode($record->draft_options ?? 'null', true),
                    'published_slides' => json_decode($record->published_slides ?? 'null', true),
                    'published_options' => json_decode($record->published_options ?? 'null', true),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'user_id' => null,
                'created_at' => now(),
            ]);
            $changes['updated_at'] = now();
            DB::table('page_builder_components')->where('id', $record->id)->update($changes);

            // Fresh query: do not reuse the row-lock/update Query Builder.
            $updated = DB::table('page_builder_components')
                ->where('id', $record->id)->first(['draft_slides', 'published_slides']);
            foreach (['draft_slides', 'published_slides'] as $column) {
                if (! isset($changes[$column])) {
                    continue;
                }
                if (json_decode($updated->{$column}, true)
                    !== json_decode($changes[$column], true)) {
                    throw new \RuntimeException('Hero legacy migration verification failed.');
                }
            }

            echo 'HERO_LEGACY_CONVERSION: success counts='.json_encode($converted)."\n";
        });
    }

    public function down(): void
    {
        // Non-destructive. Restoring the prior JSON requires the explicitly
        // recorded pre-migration snapshot and controlled manual approval.
    }
};
