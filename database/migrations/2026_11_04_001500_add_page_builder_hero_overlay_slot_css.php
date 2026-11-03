<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Install default overlay positioning in the Hero component's editable CSS.
 * The slot is inert until authored page HTML uses it.
 */
return new class extends Migration
{
    private const MARKER = '/* MSKBA_HERO_OVERLAY_SLOT_20261104 */';

    private const STYLE = <<<'CSS'
.mskba-hero-slider__overlay {
    position:absolute;inset:0;z-index:3;pointer-events:none;
}
CSS;

    public function up(): void
    {
        DB::transaction(function (): void {
            $component = DB::table('page_builder_components')
                ->where('slug', 'hero-slider')->lockForUpdate()->first();

            if ($component === null) {
                return;
            }

            $changes = ['draft_css' => $this->prependOnce($component->draft_css)];

            if ($component->published_at !== null) {
                $changes['published_css'] = $this->prependOnce($component->published_css);

                if ($component->previous_css !== null) {
                    $changes['previous_css'] = $this->prependOnce($component->previous_css);
                }
            }

            DB::table('page_builder_components')->where('id', $component->id)->update($changes);
        });
    }

    private function prependOnce(?string $css): string
    {
        $css = (string) $css;
        if (str_contains($css, self::MARKER)) {
            return $css;
        }

        $updated = self::MARKER."\n".self::STYLE."\n\n".$css;
        if (strlen($updated) > 100_000) {
            throw new RuntimeException('Hero Slider CSS exceeds the Page Builder editor limit.');
        }

        return $updated;
    }

    public function down(): void
    {
        // Deliberately preserve CSS modified by editors.
    }
};
