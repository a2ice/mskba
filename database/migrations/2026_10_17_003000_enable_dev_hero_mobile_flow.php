<?php

use App\Modules\Content\Application\Services\HeroSliderMobileFlowPatch;
use App\Modules\Content\Application\Services\HeroSliderSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment('staging')
            || config('app.url') !== 'https://dev.mskba.ru') {
            echo "HERO_MOBILE_FLOW: skipped, not DEV\n";

            return;
        }

        DB::transaction(function (): void {
            $row = DB::table('page_builder_components')->where('slug', 'hero-slider')
                ->lockForUpdate()->first();
            if ($row === null) {
                echo "HERO_MOBILE_FLOW: component absent\n";
                return;
            }

            $changes = [];
            $resultSummary = [];
            $patch = new HeroSliderMobileFlowPatch();
            foreach (['draft_slides', 'published_slides'] as $column) {
                if ($row->{$column} === null) {
                    $resultSummary[$column] = 'absent';
                    continue;
                }
                $original = json_decode($row->{$column}, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($original) || ! array_is_list($original)) {
                    throw new \RuntimeException('Hero Slider has malformed '.$column);
                }

                $result = $patch->apply($original);
                $resultSummary[$column] = $result['result'].'/'.$result['changed_fields'];
                if ($result['changed_fields'] === 0) {
                    continue;
                }
                $optionsField = $column === 'draft_slides' ? 'draft_options' : 'published_options';
                $options = $row->{$optionsField} === null
                    ? [] : json_decode($row->{$optionsField}, true, 512, JSON_THROW_ON_ERROR);
                HeroSliderSettings::validate($result['slides'], $options);
                $changes[$column] = json_encode($result['slides'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }

            if ($changes === []) {
                echo 'HERO_MOBILE_FLOW: no changes '.json_encode($resultSummary)."\n";
                return;
            }

            DB::table('page_builder_revisions')->insert([
                'entity_type' => 'component',
                'entity_id' => $row->id,
                'action' => 'layout_backup',
                'snapshot' => json_encode([
                    'patch' => 'dev_mobile_flow_20261017',
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
            $updated = DB::table('page_builder_components')->where('id', $row->id)
                ->first(['draft_slides', 'published_slides']);
            if ($updated === null) {
                throw new \RuntimeException('Hero Slider disappeared while updating.');
            }
            foreach (['draft_slides', 'published_slides'] as $column) {
                if (isset($changes[$column])
                    && json_decode($updated->{$column}, true) !== json_decode($changes[$column], true)) {
                    throw new \RuntimeException('Hero Slider flow verification failed.');
                }
            }
            echo 'HERO_MOBILE_FLOW: SUCCESS '.json_encode($resultSummary)."\n";
        });
    }

    public function down(): void
    {
        // A later rollback must never overwrite newer manual editor changes.
        // Earlier JSON is preserved in a dedicated layout_backup revision.
    }
};
