<?php

use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Presentation\Theming\DesignTheme;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Non-destructive first step: separate published appearance theme,
        // plus a DRAFT homepage. The existing '/' publication stays untouched.
        $classic = DesignTheme::query()->where('slug', 'mskba_classic')->first();

        if (! $classic) {
            $night = DesignTheme::query()->where('slug', 'arena_night')->firstOrFail();
            $classic = $night->replicate(['slug', 'is_system', 'is_published']);
            $classic->slug = 'mskba_classic';
            $classic->name = 'MSKBA Classic';
            $classic->description = 'Классическая версия оформления портала MSKBA. Отдельное содержимое страниц.';
            $classic->is_system = false;
            $classic->is_published = true;
            $classic->save();
        }

        PageBuilderPage::query()->firstOrCreate(
            ['path' => '/', 'design_theme_id' => $classic->getKey()],
            [
                'name' => 'Главная — Classic',
                'meta_title' => 'MSKBA — баскетбол в Москве',
                'meta_description' => 'Игры, площадки, команды и турниры Московской Баскетбольной Ассоциации.',
                'draft_html' => '<mskba-component name="classic-home"></mskba-component>',
            ],
        );
    }

    public function down(): void
    {
        // Deliberately preserve editorial data: a rollback may not delete
        // a theme or draft page that an editor subsequently modified.
    }
};
