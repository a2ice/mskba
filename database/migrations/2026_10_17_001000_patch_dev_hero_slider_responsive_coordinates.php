<?php

use App\Modules\Content\Application\Services\HeroSliderResponsivePatch;
use App\Modules\Content\Application\Services\HeroSliderSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The exact development host is mandatory: this data patch must never
        // alter the main/production DB, even if the migration is later merged.
        if (! app()->environment('staging')
            || config('app.url') !== 'https://dev.mskba.ru') {
            echo "HERO_RESPONSIVE_PATCH: skipped (not isolated DEV)\\n";

            return;
        }

        DB::transaction(function (): void {
            $query = DB::table('page_builder_components')->where('slug', 'hero-slider');
            $row = $query->lockForUpdate()->first();
            if ($row !== null) {
                $keys = array_keys(get_object_vars($row));
                echo 'HERO_RESPONSIVE_PATCH: inspected columns='.implode(',', $keys)."\n";
                foreach (['id', 'draft_slides', 'published_slides'] as $required) {
                    if (! property_exists($row, $required)) {
                        throw new \RuntimeException('Missing expected page_builder_components column: '.$required);
                    }
                }
            }
            if (! $row) {
                echo "HERO_RESPONSIVE_PATCH: skipped (component absent)\\n";

                return;
            }

            $patch = new HeroSliderResponsivePatch();
            $changes = [];
            $status = [];
            foreach (['draft_slides', 'published_slides'] as $column) {
                if ($row->{$column} === null) {
                    $status[$column] = 'not_published';

                    continue;
                }

                $slides = json_decode($row->{$column}, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($slides) || ! array_is_list($slides)) {
                    $status[$column] = 'invalid_shape';

                    continue;
                }

                $result = $patch->apply($slides);
                $status[$column] = $result['result'].'/'.$result['changed_fields'];
                if ($result['changed_fields'] > 0) {
                    HeroSliderSettings::validate($result['slides'], $column === 'draft_slides'
                        ? json_decode((string) ($row->draft_options ?? '{}'), true)
                        : json_decode((string) ($row->published_options ?? '{}'), true));
                    $changes[$column] = json_encode(
                        $result['slides'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                    );
                }
            }

            if ($changes === []) {
                echo "HERO_RESPONSIVE_PATCH: no changes; ".json_encode($status)."\\n";

                return;
            }

            // Atomic DB backup before the write, including both public and
            // draft JSON; unrelated record fields are not modified.
            DB::table('page_builder_revisions')->insert([
                'entity_type' => 'component',
                'entity_id' => $row->id,
                'action' => 'layout_backup',
                'snapshot' => json_encode([
                    'patch' => 'dev_hero_responsive_20261017',
                    'name' => $row->name,
                    'draft_html' => $row->draft_html,
                    'draft_css' => $row->draft_css,
                    'draft_slides' => json_decode((string) ($row->draft_slides ?? 'null'), true),
                    'draft_options' => json_decode((string) ($row->draft_options ?? 'null'), true),
                    'published_slides' => json_decode((string) ($row->published_slides ?? 'null'), true),
                    'published_options' => json_decode((string) ($row->published_options ?? 'null'), true),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'user_id' => null,
                'created_at' => now(),
            ]);

            $changes['updated_at'] = now();
            DB::table('page_builder_components')->where('id', $row->id)->update($changes);

            // Re-read with a FRESH query and explicit projection: Query Builder
            // may retain a temporary column selection after update() and
            // return an incomplete row from the previously reused builder.
            $updated = DB::table('page_builder_components')
                ->where('id', $row->id)->first(['draft_slides', 'published_slides']);
            if ($updated === null) {
                throw new \RuntimeException('Hero Slider component vanished during verification.');
            }
            foreach (['draft_slides', 'published_slides'] as $column) {
                if (isset($changes[$column])
                    && json_decode($updated->{$column}, true) !== json_decode($changes[$column], true)) {
                    throw new \RuntimeException('Hero Slider responsive patch verification failed.');
                }
            }
            echo "HERO_RESPONSIVE_PATCH: SUCCESS ".json_encode($status)." (backup in page_builder_revisions)\\n";
        });
    }

    public function down(): void
    {
        // Deliberately non-destructive: a rollback months later must not
        // overwrite newer editor changes. The pre-patch JSON is preserved
        // in the layout_backup revision for controlled manual recovery.
    }
};
