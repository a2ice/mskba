<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // No new columns/tables: preserve all previously published JSON as-is,
        // adding only a layout marker to old, already configured slides.
        $row = DB::table('page_builder_components')->where('slug', 'hero-slider')->first();
        if (! $row) {
            return;
        }
        $changes = [];
        foreach (['draft_slides', 'published_slides'] as $column) {
            if ($row->{$column} === null) {
                continue;
            }
            $slides = json_decode($row->{$column}, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($slides)) {
                continue;
            }
            foreach ($slides as &$slide) {
                if (is_array($slide)) {
                    $slide['layout'] ??= 'legacy';
                }
            }
            unset($slide);
            $changes[$column] = json_encode($slides, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        if ($changes !== []) {
            DB::table('page_builder_components')->where('id', $row->id)->update($changes);
        }
    }

    public function down(): void
    {
        // Never discard layout flags from existing user-authored JSON.
    }
};
