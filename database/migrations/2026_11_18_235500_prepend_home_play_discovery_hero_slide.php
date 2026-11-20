<?php

use App\Modules\Content\Application\Services\HeroSliderSettings;
use App\Modules\Content\Application\Services\HomePlayDiscoverySlide;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // This is editorial content for the production homepage, not a schema change.
        if (rtrim((string) config('app.url'), '/') !== 'https://mskba.ru') {
            echo "HOME_PLAY_DISCOVERY: skipped (not mskba.ru)\n";

            return;
        }

        DB::transaction(static function (): void {
            $row = DB::table('page_builder_components')
                ->where('slug', 'hero-slider')->lockForUpdate()->first();

            if ($row === null || $row->published_at === null
                || $row->draft_slides === null || $row->published_slides === null) {
                echo "HOME_PLAY_DISCOVERY: skipped (no published slider/draft)\n";

                return;
            }

            $decode = static function (string $json): array {
                $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($value) || ! array_is_list($value)) {
                    throw new RuntimeException('Hero Slider JSON is not a list of slides.');
                }

                return $value;
            };

            $draft = $decode((string) $row->draft_slides);
            $published = $decode((string) $row->published_slides);
            $nextDraft = HomePlayDiscoverySlide::prependToFiveVenueFirstSlides($draft);
            $nextPublished = HomePlayDiscoverySlide::prependToFiveVenueFirstSlides($published);

            // Never touch content when the author's draft and live sequence differ
            // from the expected five-section narrative.
            if ($nextDraft === null || $nextPublished === null) {
                echo "HOME_PLAY_DISCOVERY: skipped (unexpected editor content)\n";

                return;
            }

            $options = static function ($value): array {
                if ($value === null) {
                    return [];
                }

                return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
            };
            HeroSliderSettings::validate($nextDraft, $options($row->draft_options));
            HeroSliderSettings::validate($nextPublished, $options($row->published_options));

            DB::table('page_builder_revisions')->insert([
                'entity_type' => 'component',
                'entity_id' => $row->id,
                'action' => 'hero_intro_backup',
                'snapshot' => json_encode([
                    'change' => 'home_play_discovery_20261118',
                    'draft_slides' => $draft,
                    'published_slides' => $published,
                    'draft_options' => $options($row->draft_options),
                    'published_options' => $options($row->published_options),
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'user_id' => null,
                'created_at' => now(),
            ]);

            // No CSS or existing slides are overwritten; Filament remains the
            // owner of the sixth (new first) slide and all later edits.
            DB::table('page_builder_components')->where('id', $row->id)->update([
                'draft_slides' => json_encode($nextDraft, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'published_slides' => json_encode($nextPublished, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            echo "HOME_PLAY_DISCOVERY: inserted first slide (6 total)\n";
        });
    }

    public function down(): void
    {
        // Do not clobber newer editorial changes on rollback. Original slider
        // is retained as hero_intro_backup in page_builder_revisions.
    }
};
